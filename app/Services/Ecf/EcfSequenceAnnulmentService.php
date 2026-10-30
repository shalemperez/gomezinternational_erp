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
 * Annuls unused electronic e-NCF ranges (ANECF): collects the ranges, signs and sends them to the DGII, and advances
 * the sequences that an accepted annulment covered.
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

    /** @throws EcfException when the range is not electronic, lies outside every sequence, was used or overlaps an annulment */
    public function addRange(NcfType $type, int $from, int $to, string $reason, User $actor): EcfAnnulmentRange
    {
        if (! $type->isElectronic()) {
            throw new EcfException('Solo se anulan secuencias electrónicas.');
        }
        $environment = $this->ecf->environment()->value;

        return DB::transaction(function () use ($type, $from, $to, $reason, $actor, $environment): EcfAnnulmentRange {
            $this->lockElectronicSequences($environment);

            $covered = NcfSequence::query()->where('ncf_type', $type->value)->where('environment', $environment)->where('is_active', true)
                ->where('range_from', '<=', $from)->where('range_to', '>=', $to)->exists();
            if (! $covered) {
                throw new EcfException("El rango no pertenece a ninguna secuencia {$type->value} registrada para este ambiente.");
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
            ]);

            activity('ecf_annulment_ranges')->causedBy($actor)->performedOn($range)->event('created')
                ->withProperties(['ncf_type' => $type->value, 'range_from' => $from, 'range_to' => $to])
                ->log('Rango agregado a la anulación pendiente');

            return $range;
        });
    }

    /**
     * Builds, signs and validates the ANECF, stores it and posts it to the DGII. The whole call runs in one transaction
     * so a provider failure leaves the ranges pending and the sequences untouched.
     *
     * @param  list<int>  $rangeIds
     *
     * @throws EcfException when a range is not pending for the current environment, there is no active certificate or the provider fails
     */
    public function send(array $rangeIds, User $actor): EcfAnnulment
    {
        $environment = $this->ecf->environment();
        $certificate = $this->certificates->active() ?? throw new EcfException('No hay un certificado activo para firmar la anulación.');

        return DB::transaction(function () use ($rangeIds, $actor, $environment, $certificate): EcfAnnulment {
            $this->lockElectronicSequences($environment->value);

            $ranges = EcfAnnulmentRange::query()->whereKey($rangeIds)->whereNull('ecf_annulment_id')->where('environment', $environment->value)
                ->orderBy('ncf_type')->orderBy('range_from')->lockForUpdate()->get();
            if ($ranges->isEmpty() || $ranges->count() !== count(array_unique($rangeIds))) {
                throw new EcfException('Solo se envían rangos pendientes del ambiente actual.');
            }

            $annulment = EcfAnnulment::query()->create([
                'environment' => $environment->value, 'status' => 'pending', 'created_by' => $actor->id,
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
                'xml_path' => $this->storage->putNew("ecf/{$environment->value}/anulaciones/ANECF-{$now->format('YmdHis')}.xml", $signed->xml),
            ]);

            $result = $this->ecf->provider()->annul($annulment, $signed->xml);
            activity('ecf_annulments')->causedBy($actor)->performedOn($annulment)->event('sent')
                ->withProperties(['ranges' => $this->rangeSummary($ranges)])
                ->log('Anulación enviada a la DGII');

            $accepted = $result->status === EcfStatus::Accepted;
            $annulment->update([
                'status' => $accepted ? 'accepted' : 'rejected',
                'response' => $result->raw,
                'sent_at' => $accepted ? now() : null,
            ]);

            if ($accepted) {
                $this->advanceSequences($ranges, $environment->value);
            } else {
                $ranges->each(fn (EcfAnnulmentRange $range) => $range->update(['ecf_annulment_id' => null]));
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

    /** Moves each sequence past the accepted ranges that cover its next number, as the DGII now treats those numbers as annulled. */
    /** @param  Collection<int, EcfAnnulmentRange>  $ranges */
    private function advanceSequences(Collection $ranges, string $environment): void
    {
        foreach ($ranges as $range) {
            $sequence = NcfSequence::query()->where('ncf_type', $range->ncf_type)->where('environment', $environment)->where('is_active', true)
                ->where('range_from', '<=', $range->range_from)->where('range_to', '>=', $range->range_to)
                ->orderBy('range_from')->first();
            if ($sequence === null || $sequence->next_number < $range->range_from || $sequence->next_number > $range->range_to) {
                continue;
            }

            $sequence->next_number = (int) $range->range_to + 1;
            if ($sequence->next_number > $sequence->range_to) {
                $sequence->exhausted_at = now();
            }
            $sequence->save();
        }
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
