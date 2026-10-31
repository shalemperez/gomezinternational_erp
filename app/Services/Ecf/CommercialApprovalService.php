<?php

namespace App\Services\Ecf;

use App\Exceptions\EcfException;
use App\Models\ReceivedEcf;
use App\Models\User;
use App\Services\Settings;
use App\Support\FiscalId;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Commercial decision on an e-CF received from a supplier: the ACECF is signed and sent to the DGII first, then delivered
 * to the issuer when the DGII directory lists an acceptance URL. The row is marked `sending` under lock before the DGII call,
 * so two clicks cannot send two ACECF. The DGII is called outside the database lock; the decision is re-checked and saved
 * inside it.
 */
class CommercialApprovalService
{
    private const TIMEZONE = 'America/Santo_Domingo';

    public function __construct(
        private readonly DgiiClient $dgii,
        private readonly XmlSigner $signer,
        private readonly XsdValidator $xsd,
        private readonly EcfStorage $storage,
        private readonly CertificateStore $certificates,
        private readonly EcfXmlWriter $writer,
        private readonly Settings $settings,
    ) {}

    /**
     * @throws EcfException when the e-CF already has a decision, a rejection has no reason or the DGII refuses the ACECF
     */
    public function decide(ReceivedEcf $received, User $actor, bool $approve, ?string $reason): ReceivedEcf
    {
        if (! $approve && blank($reason)) {
            throw new EcfException('Indica el motivo del rechazo comercial.');
        }

        $this->claim($received);

        try {
            $now = CarbonImmutable::now(self::TIMEZONE);
            $companyRnc = FiscalId::normalize((string) $this->settings->get('company.rnc', ''));
            $fechaEmision = $received->fecha_emision ?? throw new EcfException('El e-CF recibido no tiene fecha de emisión.');
            $acecf = $this->writer->writeAcecf(
                $received->rnc_emisor,
                $received->encf,
                CarbonImmutable::instance($fechaEmision),
                $received->monto_total,
                $companyRnc,
                $approve ? 1 : 2,
                $approve ? null : $reason,
                $now,
            );
            $signed = $this->signer->sign($acecf, $this->certificates->requireActive());

            $document = new DOMDocument;
            $document->loadXML($signed->xml);
            $this->xsd->validate($document, 'acecf');

            $fileName = $companyRnc.$received->encf.'.xml';
            $directory = dirname($received->xml_path);
            $path = "{$directory}/ACECF-{$received->rnc_emisor}{$received->encf}.xml";
            if ($this->storage->exists($path)) {
                $path = "{$directory}/ACECF-{$received->rnc_emisor}{$received->encf}-".Str::uuid()->toString().'.xml';
            }
            $this->storage->putNew($path, $signed->xml);

            $dgii = $this->dgii->commercialApproval($signed->xml, $fileName);
            if ($dgii['status'] < 200 || $dgii['status'] >= 300) {
                $message = is_string($dgii['json']['mensaje'] ?? null) ? $dgii['json']['mensaje'] : 'sin detalle';

                throw new EcfException("La DGII rechazó la aprobación comercial: {$message}");
            }

            $issuer = $this->deliverToIssuer($received, $signed->xml, $fileName);
        } catch (Throwable $exception) {
            $this->release($received);

            throw $exception;
        }

        return DB::transaction(function () use ($received, $approve, $reason, $actor, $now, $path, $dgii, $issuer): ReceivedEcf {
            $locked = ReceivedEcf::query()->whereKey($received->id)->lockForUpdate()->firstOrFail();
            if ($locked->commercial_status !== 'sending') {
                throw new EcfException('Este e-CF ya tiene una decisión comercial.');
            }

            $locked->forceFill([
                'commercial_status' => $approve ? 'approved' : 'rejected',
                'commercial_reason' => $approve ? null : $reason,
                'acecf_path' => $path,
                'commercial_sent_at' => $now,
                'commercial_by' => $actor->id,
                'notes' => $this->appendNote($locked->notes, "Entrega al emisor: {$issuer['delivery']}"),
            ])->save();

            activity('received_ecfs')->performedOn($locked)->event($approve ? 'commercially_approved' : 'commercially_rejected')
                ->withProperties(['reason' => $approve ? null : $reason, 'dgii_http' => $dgii['status'], 'issuer_http' => $issuer['http']])
                ->log($approve ? 'Aprobación comercial enviada a la DGII' : 'Rechazo comercial enviado a la DGII');

            return $locked;
        });
    }

    /** @throws EcfException when the e-CF already has a commercial decision or one is being sent */
    private function assertPending(ReceivedEcf $received): void
    {
        if ($received->commercial_status === 'sending') {
            throw new EcfException('Ya se está enviando una decisión comercial para este e-CF.');
        }

        if ($received->commercial_status !== 'pending') {
            throw new EcfException('Este e-CF ya tiene una decisión comercial.');
        }
    }

    /** @throws EcfException when the e-CF already has a commercial decision or one is being sent */
    private function claim(ReceivedEcf $received): void
    {
        DB::transaction(function () use ($received): void {
            $locked = ReceivedEcf::query()->whereKey($received->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $locked->forceFill(['commercial_status' => 'sending'])->save();
        });
    }

    /** Puts a claimed e-CF back to pending when sending failed; only a row still marked `sending` is released. */
    private function release(ReceivedEcf $received): void
    {
        DB::transaction(fn () => ReceivedEcf::query()->whereKey($received->id)->where('commercial_status', 'sending')->update(['commercial_status' => 'pending']));
    }

    /**
     * Best effort: the decision already stands at the DGII, so a directory or issuer failure is recorded in the notes
     * instead of failing the request.
     *
     * @return array{delivery: string, http: ?int}
     */
    private function deliverToIssuer(ReceivedEcf $received, string $xml, string $fileName): array
    {
        try {
            $entry = $this->dgii->directoryByRnc($received->rnc_emisor);
        } catch (EcfException) {
            return ['delivery' => 'sin respuesta', 'http' => null];
        }

        $url = $entry['urlAceptacion'] ?? null;
        if (! is_string($url) || blank($url)) {
            return ['delivery' => 'sin URL', 'http' => null];
        }

        $http = $this->dgii->postToIssuer($url, $xml, $fileName);

        return ['delivery' => $http === null ? 'sin respuesta' : "HTTP {$http}", 'http' => $http];
    }

    private function appendNote(?string $notes, string $line): string
    {
        return filled($notes) ? "{$notes}\n{$line}" : $line;
    }
}
