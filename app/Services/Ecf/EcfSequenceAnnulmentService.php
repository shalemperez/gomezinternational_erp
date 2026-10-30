<?php

namespace App\Services\Ecf;

use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\EcfAnnulment;
use App\Models\EcfAnnulmentRange;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\User;
use App\Services\Settings;
use App\Support\FiscalId;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Annuls unused electronic e-NCF ranges (ANECF): collects the ranges, signs and sends them to the DGII, and shrinks the
 * sequences whose unused tail is under annulment, so their numbers are never issued.
 */
class EcfSequenceAnnulmentService
{
    public function __construct(
        private readonly EcfService $ecf,
        private readonly EcfXmlWriter $writer,
        private readonly XmlSigner $signer,
        private readonly XsdValidator $validator,
        private readonly EcfStorage $storage,
        private readonly CertificateStore $certificates,
        private readonly Settings $settings,
    ) {}

    /** @return EloquentCollection<int, EcfAnnulmentRange> */
    public function pendingRanges(): EloquentCollection
    {
        return EcfAnnulmentRange::query()
            ->with('ecfDocument.document')
            ->where('environment', $this->ecf->environment()->value)
            ->whereNull('ecf_annulment_id')
            ->orderBy('ncf_type')
            ->orderBy('range_from')
            ->get();
    }

    /**
     * Only the unused tail of a sequence can be annulled (GC-25): the range must end at the sequence end and start at or
     * after its next number. Adding it shrinks the sequence to end before the range, so its numbers are never issued.
     *
     * @throws EcfException when the range is not electronic, is not the unused tail of a sequence, was used or overlaps an annulment
     */
    public function addRange(NcfType $type, int $from, int $to, string $reason, User $actor): EcfAnnulmentRange
    {
        if (! $type->isElectronic()) {
            throw new EcfException('Solo se anulan secuencias electrónicas.');
        }
        $environment = $this->ecf->environment()->value;

        return DB::transaction(function () use ($type, $from, $to, $reason, $actor, $environment): EcfAnnulmentRange {
            $this->lockElectronicSequences($environment);

            $sequence = NcfSequence::query()->where('ncf_type', $type->value)->where('environment', $environment)->where('is_active', true)
                ->where('range_from', '<=', $from)->where('range_to', '>=', $to)->orderBy('range_from')->first();
            if ($sequence === null) {
                throw new EcfException("El rango no pertenece a ninguna secuencia {$type->value} registrada para este ambiente.");
            }

            if ($from < $sequence->next_number || $to !== (int) $sequence->range_to) {
                throw new EcfException('Solo se puede anular el tramo final sin usar de la secuencia: el rango debe empezar en '
                    ."{$this->encf($type, $sequence->next_number)} o después y terminar en {$this->encf($type, (int) $sequence->range_to)}.");
            }

            $firstUsed = $this->firstUsedEncf($type, $from, $to);
            if ($firstUsed !== null) {
                throw new EcfException("El rango incluye e-NCF ya utilizados ({$firstUsed}).");
            }

            if ($this->overlapsAnnulment($type, $from, $to, $environment)) {
                throw new EcfException('El rango se solapa con una anulación pendiente.');
            }

            $range = EcfAnnulmentRange::query()->create([
                'ncf_type' => $type->value, 'environment' => $environment, 'range_from' => $from, 'range_to' => $to,
                'quantity' => $to - $from + 1, 'reason' => $reason,
                'ncf_sequence_id' => $sequence->id, 'original_range_to' => (int) $sequence->range_to,
            ]);

            if ($from < 1) {
                throw new EcfException('El inicio del rango debe ser mayor que cero.');
            }

            $sequence->range_to = $from - 1;
            if ($sequence->next_number > $sequence->range_to) {
                $sequence->exhausted_at = now();
            }
            $sequence->save();

            activity('ecf_annulment_ranges')->causedBy($actor)->performedOn($range)->event('created')
                ->withProperties(['ncf_type' => $type->value, 'range_from' => $from, 'range_to' => $to, 'original_range_to' => $range->original_range_to])
                ->log('Rango agregado a la anulación pendiente');

            return $range;
        });
    }

    /**
     * Deletes a pending range that does not come from a cancelled invoice, and gives a tail range back to its sequence.
     *
     * @throws EcfException when the range is not pending or comes from a cancelled invoice
     */
    public function deleteRange(EcfAnnulmentRange $range, User $actor): void
    {
        DB::transaction(function () use ($range, $actor): void {
            $this->lockElectronicSequences($range->environment);

            $pending = EcfAnnulmentRange::query()->whereKey($range->id)->whereNull('ecf_annulment_id')->lockForUpdate()->first();
            if ($pending === null) {
                throw new EcfException('Solo se eliminan rangos pendientes');
            }
            if ($pending->ecf_document_id !== null) {
                throw new EcfException('Este rango proviene de una factura anulada y debe enviarse a la DGII.');
            }

            $this->restoreTail($pending);

            activity('ecf_annulments')->causedBy($actor)->performedOn($pending)->event('range_deleted')
                ->withProperties(['ncf_type' => $pending->ncf_type, 'range_from' => (int) $pending->range_from, 'range_to' => (int) $pending->range_to])
                ->log('Rango pendiente eliminado');
            $pending->delete();
        });
    }

    /**
     * Builds, signs and validates the ANECF and stores it in a transaction, then posts it to the DGII outside any
     * transaction so the sequence locks are never held during the network call. A provider failure marks the annulment
     * as failed and leaves the ranges pending; the result is applied in a second transaction.
     *
     * @param  list<int>  $rangeIds
     *
     * @throws EcfException when a range is not pending for the current environment, there is no active certificate or the provider fails
     */
    public function send(array $rangeIds, User $actor): EcfAnnulment
    {
        $environment = $this->ecf->environment();
        $certificate = $this->certificates->active() ?? throw new EcfException('No hay un certificado activo para firmar la anulación.');

        [$annulment, $ranges, $signed] = DB::transaction(function () use ($rangeIds, $actor, $environment, $certificate): array {
            $this->lockElectronicSequences($environment->value);

            $ranges = EcfAnnulmentRange::query()->whereKey($rangeIds)->whereNull('ecf_annulment_id')->where('environment', $environment->value)
                ->orderBy('ncf_type')->orderBy('range_from')->lockForUpdate()->get();
            if ($ranges->isEmpty() || $ranges->count() !== count(array_unique($rangeIds))) {
                throw new EcfException('Solo se envían rangos pendientes del ambiente actual.');
            }

            $annulment = EcfAnnulment::query()->create([
                'environment' => $environment->value, 'status' => 'sending', 'created_by' => $actor->id,
                'ranges_count' => count($ranges), 'quantity' => (int) $ranges->sum('quantity'),
            ]);
            $ranges->each(fn (EcfAnnulmentRange $range) => $range->update(['ecf_annulment_id' => $annulment->id]));

            activity('ecf_annulments')->causedBy($actor)->performedOn($annulment)->event('created')
                ->withProperties(['ranges' => $this->rangeSummary($ranges)])
                ->log('Anulación de secuencias creada');

            $now = CarbonImmutable::now('America/Santo_Domingo');
            $rnc = FiscalId::normalize((string) $this->settings->get('company.rnc', ''));
            $signed = $this->signer->sign($this->writer->writeAnecf($rnc, $ranges, $now), $certificate);
            $this->validator->validate($this->load($signed->xml), 'anecf');
            $annulment->update([
                'xml_path' => $this->storage->putNew("ecf/{$environment->value}/anulaciones/ANECF-{$annulment->id}-{$now->format('YmdHis')}.xml", $signed->xml),
            ]);

            return [$annulment, $ranges, $signed];
        });

        try {
            $result = $this->ecf->provider()->annul($annulment, $signed->xml);
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            DB::transaction(function () use ($annulment, $ranges, $actor, $error, $environment) {
                $this->lockElectronicSequences($environment->value);
                $annulment->update(['status' => 'failed', 'response' => ['error' => $error]]);
                $this->releaseRanges($ranges);

                activity('ecf_annulments')->causedBy($actor)->performedOn($annulment)->event('failed')
                    ->withProperties(['error' => $error])
                    ->log('Falló el envío de la anulación');
            });

            throw $e instanceof EcfException ? $e : new EcfException('No se pudo enviar la anulación a la DGII: '.$e->getMessage(), 0, $e);
        }

        activity('ecf_annulments')->causedBy($actor)->performedOn($annulment)->event('sent')
            ->withProperties(['ranges' => $this->rangeSummary($ranges)])
            ->log('Anulación enviada a la DGII');

        return DB::transaction(function () use ($annulment, $ranges, $actor, $environment, $result): EcfAnnulment {
            $this->lockElectronicSequences($environment->value);

            $accepted = $result->status === EcfStatus::Accepted;
            $annulment->update([
                'status' => $accepted ? 'accepted' : 'rejected',
                'response' => $result->raw,
                'sent_at' => $accepted ? now() : null,
            ]);

            if (! $accepted) {
                $this->releaseRanges($ranges);
            }

            activity('ecf_annulments')->causedBy($actor)->performedOn($annulment)->event($accepted ? 'accepted' : 'rejected')
                ->withProperties(['ranges' => $this->rangeSummary($ranges), 'codigo' => $result->code, 'mensajes' => $result->messages])
                ->log($accepted ? 'Anulación aceptada por la DGII' : 'Anulación rechazada por la DGII');

            return $annulment->refresh();
        });
    }

    /** The e-NCF with the given sequential number, e.g. E31 + 1 → E310000000001. */
    public function encf(NcfType $type, int $number): string
    {
        return $type->value.str_pad((string) $number, $type->sequenceDigits(), '0', STR_PAD_LEFT);
    }

    /** Locks the electronic sequences first, then the ranges: the same order in every path that writes annulments. */
    private function lockElectronicSequences(string $environment): void
    {
        NcfSequence::query()->where('environment', $environment)->where('ncf_type', 'like', 'E%')->orderBy('id')->lockForUpdate()->get();
    }

    /** The first e-NCF of the range already issued on an invoice, credit note or debit note (`ncf` holds the e-NCF in ecf mode). */
    private function firstUsedEncf(NcfType $type, int $from, int $to): ?string
    {
        $fromEncf = $this->encf($type, $from);
        $toEncf = $this->encf($type, $to);

        $used = [
            Invoice::query()->where('ncf_type', $type->value)->whereBetween('ncf', [$fromEncf, $toEncf])->orderBy('ncf')->value('ncf'),
            CreditNote::query()->where('ncf_type', $type->value)->whereBetween('ncf', [$fromEncf, $toEncf])->orderBy('ncf')->value('ncf'),
            DebitNote::query()->where('ncf_type', $type->value)->whereBetween('ncf', [$fromEncf, $toEncf])->orderBy('ncf')->value('ncf'),
        ];

        return collect($used)->filter()->sort()->first();
    }

    /** A pending range or one of an accepted annulment blocks the numbers it covers; a rejected annulment releases them. */
    private function overlapsAnnulment(NcfType $type, int $from, int $to, string $environment): bool
    {
        return EcfAnnulmentRange::query()->where('ncf_type', $type->value)->where('environment', $environment)
            ->where('range_from', '<=', $to)->where('range_to', '>=', $from)
            ->where(fn (Builder $query) => $query->whereNull('ecf_annulment_id')
                ->orWhereHas('annulment', fn (Builder $annulment) => $annulment->where('status', 'accepted')))
            ->exists();
    }

    /**
     * Frees the ranges of cancelled invoices, and gives the tail ranges back to their sequences. Tail ranges keep their
     * link to the annulment as history; the user re-adds them to retry.
     *
     * @param  Collection<int, EcfAnnulmentRange>  $ranges
     */
    private function releaseRanges(Collection $ranges): void
    {
        $ranges->each(function (EcfAnnulmentRange $range): void {
            if ($range->ecf_document_id !== null) {
                $range->update(['ecf_annulment_id' => null]);
            } else {
                $this->restoreTail($range);
            }
        });
    }

    /**
     * Gives a released tail back to its sequence; skipped when a later tail already shrank the sequence further (those
     * numbers then stay unused, never reissued).
     */
    private function restoreTail(EcfAnnulmentRange $range): void
    {
        if ($range->ncf_sequence_id === null || $range->original_range_to === null) {
            return;
        }

        $sequence = NcfSequence::query()->whereKey($range->ncf_sequence_id)->lockForUpdate()->first();
        if ($sequence === null || (int) $sequence->range_to !== $range->range_from - 1) {
            return;
        }

        $sequence->range_to = $range->original_range_to;
        if ($sequence->next_number <= $sequence->range_to) {
            $sequence->exhausted_at = null;
        }
        $sequence->save();
    }

    /**
     * @param  Collection<int, EcfAnnulmentRange>  $ranges
     * @return array<int, array{ncf_type: string, range_from: int, range_to: int}>
     */
    private function rangeSummary(Collection $ranges): array
    {
        return $ranges->map(fn (EcfAnnulmentRange $range) => [
            'ncf_type' => $range->ncf_type, 'range_from' => (int) $range->range_from, 'range_to' => (int) $range->range_to,
        ])->values()->all();
    }

    private function load(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->loadXML($xml);

        return $document;
    }
}
