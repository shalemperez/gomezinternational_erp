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
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfSubmissionResult;
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

            return array_key_exists('codigo', $response['json'])
                ? $this->dgiiResult($response['json'], $response['status'], null)
                : $this->rejection($response['status'], $response['json']);
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

        return $this->rejection($response['status'], $response['json']);
    }

    /**
     * @throws EcfException when the DGII cannot be reached or answers with an unknown status code
     */
    public function poll(EcfDocument $document): EcfSubmissionResult
    {
        $response = $this->client->result((string) $document->track_id);

        return $this->dgiiResult($response['json'], $response['status'], $document->track_id);
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
        $messages = is_array($json['mensajes'] ?? null) ? $json['mensajes'] : [];

        return new EcfSubmissionResult(
            status: $accepted ? EcfStatus::Accepted : EcfStatus::Rejected,
            trackId: null,
            code: isset($json['codigo']) ? (int) $json['codigo'] : null,
            messages: $accepted ? [] : array_values(array_map(fn (mixed $message): array => ['codigo' => '', 'valor' => (string) $message], $messages)),
            raw: $json,
            sequenceUsed: null,
            httpStatus: $response['status'],
        );
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

    /** @param  array<string, mixed>  $json */
    private function rejection(int $httpStatus, array $json): EcfSubmissionResult
    {
        return new EcfSubmissionResult(
            status: EcfStatus::Rejected,
            trackId: null,
            code: null,
            messages: [['codigo' => (string) $httpStatus, 'valor' => (string) ($json['mensaje'] ?? $json['error'] ?? 'Rechazado por la DGII')]],
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
