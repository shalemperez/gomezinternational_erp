<?php

namespace App\Services\Ecf\Providers;

use App\Contracts\EcfProvider;
use App\Enums\EcfStatus;
use App\Exceptions\EcfException;
use App\Models\EcfAnnulment;
use App\Models\EcfDocument;
use App\Services\Ecf\CertificateStore;
use App\Services\Ecf\DgiiClient;
use App\Services\Ecf\EcfStorage;
use App\Services\Ecf\LocalXmlPreparer;
use App\Services\Settings;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfSubmissionResult;
use App\Support\FiscalId;
use InvalidArgumentException;

/**
 * The ERP talks to the DGII directly: it signs locally, sends through DgiiClient and polls for the final status.
 */
class DgiiDirectProvider implements EcfProvider
{
    public function __construct(
        private readonly DgiiClient $client,
        private readonly CertificateStore $certificates,
        private readonly LocalXmlPreparer $preparer,
        private readonly EcfStorage $storage,
        private readonly Settings $settings,
    ) {}

    public function name(): string
    {
        return 'dgii';
    }

    public function signsLocally(): bool
    {
        return true;
    }

    public function prepare(EcfDocument $document, EcfDocumentData $data): void
    {
        $this->preparer->prepare($document, $data, $this->certificates->requireActive());
    }

    /**
     * @throws EcfException when the DGII cannot be reached or answers with an unknown status code
     */
    public function submit(EcfDocument $document): EcfSubmissionResult
    {
        if ($document->isRfce()) {
            $response = $this->client->receiveRfce($this->storage->get((string) $document->rfce_xml_path), basename((string) $document->rfce_xml_path));

            if (array_key_exists('codigo', $response['json'])) {
                return $this->dgiiResult($response['json'], $response['status'], null);
            }

            return $this->answer($response['status'], $response['json']);
        }

        $response = $this->client->receive($this->storage->get((string) $document->xml_path), basename((string) $document->xml_path));
        if (filled($response['json']['trackId'] ?? null)) {
            return new EcfSubmissionResult(
                status: EcfStatus::Sent,
                trackId: (string) $response['json']['trackId'],
                code: null,
                messages: [],
                raw: $response['json'],
                sequenceUsed: null,
                httpStatus: $response['status'],
            );
        }

        return $this->answer($response['status'], $response['json']);
    }

    /**
     * @throws EcfException when the DGII cannot be reached or answers with an unknown status code
     */
    public function poll(EcfDocument $document): EcfSubmissionResult
    {
        return $this->resultFor((string) $document->track_id);
    }

    /**
     * The estado text of the trackId list is not trusted: each trackId is resolved through consultaresultado.
     *
     * @throws EcfException when the DGII cannot be reached or answers with an unexpected status
     */
    public function lookup(EcfDocument $document): ?EcfSubmissionResult
    {
        if ($document->isRfce() || ! $this->client->hasEndpoint('consulta_trackids')) {
            return null;
        }

        $response = $this->client->trackIds(FiscalId::normalize((string) $this->settings->get('company.rnc', '')), (string) $document->encf);
        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new EcfException("Respuesta inesperada de la DGII al consultar los trackId ({$response['status']})");
        }

        $trackIds = collect($response['json'])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): string => (string) ($entry['trackId'] ?? $entry['trackid'] ?? ''))
            ->filter(fn (string $trackId): bool => $trackId !== '');
        if ($trackIds->isEmpty()) {
            return null;
        }

        $results = $trackIds->map(fn (string $trackId): EcfSubmissionResult => $this->resultFor($trackId));

        return $results->first(fn (EcfSubmissionResult $result): bool => $result->status->isValidForTax()) ?? $results->last();
    }

    /**
     * @throws EcfException when the DGII cannot be reached
     */
    public function annul(EcfAnnulment $annulment, string $signedXml): EcfSubmissionResult
    {
        $fileName = $annulment->xml_path !== null ? basename($annulment->xml_path) : 'ANECF.xml';
        $response = $this->client->annulRange($signedXml, $fileName);
        $json = $response['json'];

        $accepted = (string) ($json['codigo'] ?? '') === '1' || str_starts_with(strtolower((string) ($json['nombre'] ?? '')), 'acept');

        return new EcfSubmissionResult(
            status: $accepted ? EcfStatus::Accepted : EcfStatus::Rejected,
            trackId: null,
            code: isset($json['codigo']) ? (int) $json['codigo'] : null,
            messages: $accepted ? [] : $this->messages($json['mensajes'] ?? []),
            raw: $json,
            sequenceUsed: null,
            httpStatus: $response['status'],
        );
    }

    /**
     * @throws EcfException when the DGII cannot be reached or answers with an unknown status code
     */
    private function resultFor(string $trackId): EcfSubmissionResult
    {
        $response = $this->client->result($trackId);

        return $this->dgiiResult($response['json'], $response['status'], $trackId);
    }

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws EcfException when the DGII answers with a code outside the Estado table
     */
    private function dgiiResult(array $json, int $httpStatus, ?string $trackId): EcfSubmissionResult
    {
        $status = $this->statusOf($json['codigo'] ?? null);

        return new EcfSubmissionResult(
            status: $status,
            trackId: $trackId,
            code: (int) $json['codigo'],
            messages: $this->messages($json['mensajes'] ?? []),
            raw: $json,
            sequenceUsed: isset($json['secuenciaUtilizada']) ? (bool) $json['secuenciaUtilizada'] : null,
            httpStatus: $httpStatus,
        );
    }

    /**
     * Only HTTP 400 is a fiscal rejection: a rejected e-NCF is burned for good, so any other answer is a transient failure
     * that the caller retries.
     *
     * @param  array<string, mixed>  $json
     *
     * @throws EcfException on any status other than 400 without a result
     */
    private function answer(int $httpStatus, array $json): EcfSubmissionResult
    {
        if ($httpStatus === 400) {
            return $this->rejection($httpStatus, $json);
        }

        throw new EcfException("Respuesta inesperada de la DGII ({$httpStatus}): ".mb_substr(json_encode($json) ?: '', 0, 300));
    }

    /** @param  array<string, mixed>  $json */
    private function rejection(int $httpStatus, array $json): EcfSubmissionResult
    {
        $firstMessage = $this->messages($json['mensajes'] ?? [])[0]['valor'] ?? null;

        return new EcfSubmissionResult(
            status: EcfStatus::Rejected,
            trackId: null,
            code: null,
            messages: [['codigo' => (string) $httpStatus, 'valor' => (string) ($json['mensaje'] ?? $json['error'] ?? $firstMessage ?? 'Rechazado por la DGII')]],
            raw: $json,
            sequenceUsed: null,
            httpStatus: $httpStatus,
        );
    }

    /**
     * An unknown code is a provider error: the caller records it and retries later, the status stays unchanged.
     *
     * @throws EcfException
     */
    private function statusOf(mixed $code): EcfStatus
    {
        $number = filter_var($code, FILTER_VALIDATE_INT);

        try {
            return EcfStatus::fromDgiiCode($number === false ? -1 : $number);
        } catch (InvalidArgumentException) {
            throw new EcfException('Código de estado desconocido de la DGII: '.json_encode($code));
        }
    }

    /** @return list<array{codigo: string, valor: string}> */
    private function messages(mixed $messages): array
    {
        return array_values(collect(is_array($messages) ? $messages : [])
            ->map(fn (mixed $message): array => is_array($message)
                ? ['codigo' => (string) ($message['codigo'] ?? ''), 'valor' => (string) ($message['valor'] ?? '')]
                : ['codigo' => '', 'valor' => (string) $message])
            ->all());
    }
}
