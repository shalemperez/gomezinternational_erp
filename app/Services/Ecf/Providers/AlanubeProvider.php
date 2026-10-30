<?php

namespace App\Services\Ecf\Providers;

use App\Contracts\EcfProvider;
use App\Enums\EcfStatus;
use App\Exceptions\EcfException;
use App\Models\EcfAnnulment;
use App\Models\EcfDocument;
use App\Services\Ecf\AlanubeClient;
use App\Services\Ecf\AlanubePayloadMapper;
use App\Services\Ecf\EcfStorage;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfSubmissionResult;
use Carbon\CarbonImmutable;

/**
 * The PSFE (Alanube) signs and transmits the e-CF: the ERP stores the JSON request, posts it and maps the legal status
 * that Alanube returns.
 */
class AlanubeProvider implements EcfProvider
{
    private const REQUEST_FILE = 'alanube-request.json';

    public function __construct(
        private readonly AlanubeClient $client,
        private readonly AlanubePayloadMapper $mapper,
        private readonly EcfStorage $storage,
    ) {}

    public function name(): string
    {
        return 'alanube';
    }

    public function signsLocally(): bool
    {
        return false;
    }

    public function prepare(EcfDocument $document, EcfDocumentData $data): void
    {
        $json = json_encode($this->mapper->toPayload($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->storage->putNew($this->storage->documentDir($document).'/'.self::REQUEST_FILE, $json);
    }

    /**
     * @throws EcfException when Alanube rejects the request or cannot be reached
     */
    public function submit(EcfDocument $document): EcfSubmissionResult
    {
        $payload = json_decode($this->storage->get($this->storage->documentDir($document).'/'.self::REQUEST_FILE), true, 512, JSON_THROW_ON_ERROR);

        return $this->fromAlanube($this->client->post($this->path($document), (array) $payload));
    }

    /**
     * @throws EcfException when Alanube cannot be reached
     */
    public function poll(EcfDocument $document): EcfSubmissionResult
    {
        return $this->fromAlanube($this->client->get($this->path($document).'/'.rawurlencode((string) $document->track_id)));
    }

    /**
     * @throws EcfException when Alanube cannot be reached
     */
    public function lookup(EcfDocument $document): ?EcfSubmissionResult
    {
        return blank($document->track_id) ? null : $this->poll($document);
    }

    /**
     * @throws EcfException always: the annulment of unused sequences is managed in the Alanube portal for now
     */
    public function annul(EcfAnnulment $annulment, string $signedXml): EcfSubmissionResult
    {
        throw new EcfException('La anulación de secuencias con Alanube se gestiona desde el portal del proveedor.');
    }

    /**
     * @throws EcfException when the e-CF type has no Alanube collection
     */
    private function path(EcfDocument $document): string
    {
        $path = config("ecf.alanube.paths.{$document->ncf_type->value}");
        if (! is_string($path)) {
            throw new EcfException("Alanube no admite el tipo de e-CF {$document->ncf_type->value}");
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function fromAlanube(array $json): EcfSubmissionResult
    {
        $government = is_array($json['governmentResponse'] ?? null) ? $json['governmentResponse'] : [];
        $legalStatus = (string) ($json['legalStatus'] ?? '');
        $providerStatus = (string) ($json['status'] ?? '');

        $status = match ($legalStatus) {
            'ACCEPTED' => EcfStatus::Accepted,
            'ACCEPTED_WITH_OBSERVATIONS' => EcfStatus::ConditionallyAccepted,
            'REJECTED' => EcfStatus::Rejected,
            default => $providerStatus === 'FAILED' ? EcfStatus::Rejected : EcfStatus::Sent,
        };

        $messages = $this->messages($government);
        if ($status === EcfStatus::Rejected && $legalStatus !== 'REJECTED') {
            $messages[] = ['codigo' => '', 'valor' => "Alanube: envío fallido ({$providerStatus})"];
        }

        return new EcfSubmissionResult(
            status: $status,
            trackId: filled($json['id'] ?? null) ? (string) $json['id'] : null,
            code: isset($government['code']) ? (int) $government['code'] : null,
            messages: $messages,
            raw: $json,
            sequenceUsed: isset($json['sequenceConsumed']) ? (bool) $json['sequenceConsumed'] : null,
            httpStatus: null,
            securityCode: filled($json['securityCode'] ?? null) ? (string) $json['securityCode'] : null,
            signedAt: $this->signedAt($json['signatureDate'] ?? null),
            qrUrl: filled($json['documentStampUrl'] ?? null) ? (string) $json['documentStampUrl'] : null,
            signedXml: str_starts_with((string) ($json['xml'] ?? ''), '<') ? (string) $json['xml'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $government
     * @return list<array{codigo: string, valor: string}>
     */
    private function messages(array $government): array
    {
        $values = is_array($government['value'] ?? null) ? $government['value'] : [];

        return array_values(array_map(function (mixed $message): array {
            $item = is_array($message) ? $message : [];

            return ['codigo' => (string) ($item['codigo'] ?? ''), 'valor' => (string) ($item['valor'] ?? '')];
        }, $values));
    }

    private function signedAt(mixed $signatureDate): ?CarbonImmutable
    {
        if (! is_string($signatureDate) || $signatureDate === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('d-m-Y H:i:s', $signatureDate, 'America/Santo_Domingo') ?: null;
    }
}
