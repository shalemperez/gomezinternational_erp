<?php

namespace App\Services\Ecf;

use App\Enums\NcfType;
use App\Exceptions\EcfValidationException;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\EcfDocument;
use App\Models\Invoice;
use App\Models\ReceivedEcf;
use App\Models\Supplier;
use App\Services\Settings;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receiver role (DGII-direct): keeps the e-CF that suppliers send to the company, answers each one with a signed ARECF
 * and applies the ACECF that buyers send about our own e-CF. Every answer is a Motivo code of the DGII receiver spec.
 */
class ReceivedEcfService
{
    private const TIMEZONE = 'America/Santo_Domingo';

    private const UNKNOWN_RNC = '000000000';

    private const UNKNOWN_ENCF = 'E000000000000';

    public function __construct(
        private readonly XmlSigner $signer,
        private readonly XsdValidator $xsd,
        private readonly EcfStorage $storage,
        private readonly CertificateStore $certificates,
        private readonly EcfXmlWriter $writer,
        private readonly Settings $settings,
    ) {}

    /**
     * Motivo 1 = XSD or type, 2 = signature, 3 = duplicate, 4 = buyer RNC. Refused documents with a readable e-NCF are kept
     * for the audit trail; duplicates are answered but not stored again.
     *
     * @return array{status: int, reason: ?int, xml: string, received: ?ReceivedEcf}
     */
    public function receive(string $xml, ?string $senderIp): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $companyRnc = $this->companyRnc();
        $document = $this->parse($xml);
        $identity = $document === null ? null : $this->identity($document);
        $reason = $document === null ? 1 : $this->refusalReason($document, $companyRnc, $identity);

        $duplicate = $identity !== null && $reason === null && $this->isDuplicate($identity);
        if ($duplicate) {
            $reason = 3;
        }

        $status = $reason === null ? 0 : 1;
        $acknowledged = $identity ?? $this->rawIdentity($xml) ?? ['rnc' => self::UNKNOWN_RNC, 'encf' => self::UNKNOWN_ENCF];
        $arecf = $this->acknowledgement($acknowledged['rnc'], $acknowledged['encf'], $companyRnc, $status, $reason, $now);

        $received = null;
        if ($document !== null && $identity !== null && ! $duplicate && ($reason === null || ! $this->refusedLimitReached($senderIp))) {
            try {
                $received = $this->storeReceipt($xml, $document, $identity, $status, $reason, $senderIp, $arecf, $now);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['status' => $status, 'reason' => $reason, 'xml' => $arecf, 'received' => $received];
    }

    /** Applies an ACECF about one of our e-CF. Returns false (HTTP 400) when the XML is invalid, unsigned or unknown. */
    public function applyCommercialApproval(string $xml): bool
    {
        $document = $this->parse($xml);
        if ($document === null) {
            return false;
        }

        try {
            $this->xsd->validate($document, 'acecf');
        } catch (EcfValidationException) {
            return false;
        }

        if (! $this->signer->verify($document)) {
            return false;
        }

        $xpath = new DOMXPath($document);
        $rncEmisor = $this->text($xpath, '/ACECF/DetalleAprobacionComercial/RNCEmisor');
        $encf = $this->text($xpath, '/ACECF/DetalleAprobacionComercial/eNCF');
        $estado = $this->text($xpath, '/ACECF/DetalleAprobacionComercial/Estado');
        $rncComprador = $this->text($xpath, '/ACECF/DetalleAprobacionComercial/RNCComprador');
        if ($rncEmisor !== $this->companyRnc() || ! in_array($estado, ['1', '2'], true) || ($this->requiresSignerMatch() && $this->signer->signerTaxId($document) !== $rncComprador)) {
            return false;
        }

        $ecfDocument = EcfDocument::query()->where('encf', $encf)->first();
        if ($ecfDocument === null) {
            return false;
        }

        if (FiscalId::normalize((string) $this->buyerTaxId($ecfDocument)) !== $rncComprador) {
            return false;
        }

        $approved = $estado === '1';
        $reason = $approved ? null : $this->nullIfBlank($this->text($xpath, '/ACECF/DetalleAprobacionComercial/DetalleMotivoRechazo'));
        $commercialAt = CarbonImmutable::createFromFormat('d-m-Y H:i:s', $this->text($xpath, '/ACECF/DetalleAprobacionComercial/FechaHoraAprobacionComercial'), self::TIMEZONE)
            ?: CarbonImmutable::now(self::TIMEZONE);

        $path = $this->storage->documentDir($ecfDocument).'/ACECF-in.xml';
        if ($this->storage->exists($path)) {
            $path = $this->storage->documentDir($ecfDocument).'/ACECF-in-'.Str::uuid()->toString().'.xml';
        }

        DB::transaction(function () use ($ecfDocument, $approved, $reason, $commercialAt, $path, $xml): void {
            $this->storage->putNew($path, $xml);
            $ecfDocument->forceFill([
                'commercial_status' => $approved ? 'approved' : 'rejected',
                'commercial_reason' => $reason,
                'commercial_at' => $commercialAt,
            ])->save();
            $ecfDocument->transmissions()->create([
                'kind' => 'approval_in',
                'environment' => $ecfDocument->environment->value,
                'request_path' => $path,
                'succeeded' => true,
            ]);

            activity('ecf_documents')->performedOn($ecfDocument)->event('commercial_approval_received')
                ->withProperties(['encf' => $ecfDocument->encf, 'approved' => $approved, 'reason' => $reason])
                ->log($approved ? 'Aprobación comercial recibida' : 'Rechazo comercial recibido');
        });

        return true;
    }

    /** @return array{rnc: string, encf: string}|null */
    private function identity(DOMDocument $document): ?array
    {
        $xpath = new DOMXPath($document);
        $rnc = $this->text($xpath, '/ECF/Encabezado/Emisor/RNCEmisor');
        $encf = $this->text($xpath, '/ECF/Encabezado/IdDoc/eNCF');

        if (preg_match('/^\d{9}(\d{2})?$/', $rnc) !== 1 || preg_match('/^E\d{12}$/', $encf) !== 1) {
            return null;
        }

        return ['rnc' => $rnc, 'encf' => $encf];
    }

    /**
     * Identifiers read from the raw text of an unparseable request, used only to address the ARECF.
     *
     * @return array{rnc: string, encf: string}|null
     */
    private function rawIdentity(string $xml): ?array
    {
        $rnc = preg_match('/<RNCEmisor>\s*(\d{9}|\d{11})\s*<\/RNCEmisor>/', $xml, $rncMatch) === 1 ? $rncMatch[1] : null;
        $encf = preg_match('/<eNCF>\s*(E\d{12})\s*<\/eNCF>/', $xml, $encfMatch) === 1 ? $encfMatch[1] : null;

        return $rnc !== null && $encf !== null ? ['rnc' => $rnc, 'encf' => $encf] : null;
    }

    /**
     * @param  array{rnc: string, encf: string}|null  $identity
     */
    private function refusalReason(DOMDocument $document, string $companyRnc, ?array $identity): ?int
    {
        $xpath = new DOMXPath($document);
        $type = NcfType::tryFrom('E'.$this->text($xpath, '/ECF/Encabezado/IdDoc/TipoeCF'));
        if ($type === null || $identity === null) {
            return 1;
        }

        try {
            $this->xsd->validate($document, $this->xsd->schemaFor($type));
        } catch (EcfValidationException) {
            return 1;
        }

        if (! $this->signer->verify($document)) {
            return 2;
        }

        if ($this->requiresSignerMatch() && $this->signer->signerTaxId($document) !== $identity['rnc']) {
            return 2;
        }

        return $this->text($xpath, '/ECF/Encabezado/Comprador/RNCComprador') === $companyRnc ? null : 4;
    }

    /** @param  array{rnc: string, encf: string}  $identity */
    private function isDuplicate(array $identity): bool
    {
        return ReceivedEcf::query()
            ->where('rnc_emisor', $identity['rnc'])
            ->where('encf', $identity['encf'])
            ->where('arecf_status', 0)
            ->exists();
    }

    /** Refused documents stop being stored for an IP after the daily limit (GC-26); the ARECF is still answered. */
    private function refusedLimitReached(?string $senderIp): bool
    {
        return $senderIp !== null && ReceivedEcf::query()->where('sender_ip', $senderIp)->where('arecf_status', 1)->where('received_at', '>=', now()->subDay())->count() >= (int) config('ecf.receiver.refused_per_ip_per_day');
    }

    /**
     * @param  array{rnc: string, encf: string}  $identity
     */
    private function storeReceipt(string $xml, DOMDocument $document, array $identity, int $status, ?int $reason, ?string $senderIp, string $arecf, CarbonImmutable $now): ReceivedEcf
    {
        $xpath = new DOMXPath($document);
        $isRefused = $reason !== null;
        $name = $identity['rnc'].$identity['encf'];
        $directory = 'ecf/received/'.$now->year;

        if ($reason === null) {
            $xmlPath = "{$directory}/{$name}.xml";
            $arecfPath = "{$directory}/ARECF-{$name}.xml";
        } else {
            $unique = Str::uuid()->toString();
            $xmlPath = "{$directory}/refused/{$name}-{$unique}.xml";
            $arecfPath = "{$directory}/refused/ARECF-{$name}-{$unique}.xml";
        }

        return DB::transaction(function () use ($xml, $xpath, $identity, $status, $reason, $isRefused, $senderIp, $arecf, $now, $xmlPath, $arecfPath): ReceivedEcf {
            $this->storage->putNew($xmlPath, $xml);
            $this->storage->putNew($arecfPath, $arecf);

            $received = ReceivedEcf::query()->create([
                'rnc_emisor' => $identity['rnc'],
                'razon_social_emisor' => $isRefused ? null : $this->nullIfBlank(mb_substr($this->text($xpath, '/ECF/Encabezado/Emisor/RazonSocialEmisor'), 0, 150)),
                'ncf_type' => substr($identity['encf'], 0, 3),
                'encf' => $identity['encf'],
                'rnc_comprador' => $isRefused ? null : $this->nullIfBlank($this->text($xpath, '/ECF/Encabezado/Comprador/RNCComprador')),
                'fecha_emision' => $isRefused ? null : $this->date($this->text($xpath, '/ECF/Encabezado/Emisor/FechaEmision')),
                'monto_total' => $isRefused ? '0.00' : $this->amount($this->text($xpath, '/ECF/Encabezado/Totales/MontoTotal')),
                'total_itbis' => $isRefused ? '0.00' : $this->amount($this->text($xpath, '/ECF/Encabezado/Totales/TotalITBIS')),
                'xml_path' => $xmlPath,
                'arecf_path' => $arecfPath,
                'arecf_status' => $status,
                'arecf_reason_code' => $reason,
                'received_at' => $now,
                'sender_ip' => $senderIp,
                'supplier_id' => Supplier::query()->where('tax_id', $identity['rnc'])->value('id'),
            ]);

            activity('received_ecfs')->performedOn($received)->event($reason === null ? 'received' : 'rejected_on_receipt')
                ->withProperties(['encf' => $received->encf, 'rnc_emisor' => $received->rnc_emisor, 'arecf_status' => $status, 'reason_code' => $reason])
                ->log($reason === null ? 'e-CF recibido' : 'e-CF no recibido');

            return $received;
        });
    }

    /** Builds, signs with the active certificate, validates and returns the ARECF (Estado 0 received / 1 not received). */
    private function acknowledgement(string $rncEmisor, string $encf, string $companyRnc, int $status, ?int $reason, CarbonImmutable $now): string
    {
        $certificate = $this->certificates->requireActive();
        $signed = $this->signer->sign($this->writer->writeArecf($rncEmisor, $companyRnc, $encf, $status, $reason, $now), $certificate);

        $document = new DOMDocument;
        $document->loadXML($signed->xml);
        $this->xsd->validate($document, 'arecf');

        return $signed->xml;
    }

    /** Null for DOCTYPE declarations (checked on the raw bytes and again after parsing, for UTF-16/32 input) and for malformed XML. */
    private function parse(string $xml): ?DOMDocument
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $document->doctype === null ? $document : null;
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        return trim((string) $xpath->evaluate("string({$query})"));
    }

    private function amount(string $raw): string
    {
        return is_numeric($raw) ? Decimal::money($raw) : '0.00';
    }

    private function date(string $raw): ?string
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('d-m-Y', $raw, self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable ? $parsed->toDateString() : null;
    }

    private function nullIfBlank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function requiresSignerMatch(): bool
    {
        return (bool) config('ecf.receiver.verify_signer_tax_id');
    }

    /** The buyer of an e-CF: the client of an invoice, or of the invoice a credit or debit note adjusts. */
    private function buyerTaxId(EcfDocument $ecfDocument): ?string
    {
        $document = $ecfDocument->document;

        if ($document instanceof Invoice) {
            return $document->client_tax_id;
        }

        if ($document instanceof CreditNote || $document instanceof DebitNote) {
            return $document->invoice?->client_tax_id;
        }

        return null;
    }

    private function companyRnc(): string
    {
        return FiscalId::normalize((string) $this->settings->get('company.rnc', ''));
    }
}
