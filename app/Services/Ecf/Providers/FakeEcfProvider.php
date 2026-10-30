<?php

namespace App\Services\Ecf\Providers;

use App\Contracts\EcfProvider;
use App\Enums\EcfStatus;
use App\Exceptions\EcfException;
use App\Models\EcfAnnulment;
use App\Models\EcfDocument;
use App\Services\Ecf\CertificateStore;
use App\Services\Ecf\LocalXmlPreparer;
use App\Services\Settings;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfSubmissionResult;
use Illuminate\Support\Str;

/**
 * Deterministic provider for tests and development. The outcome comes from setting `ecf.fake.outcome`.
 */
class FakeEcfProvider implements EcfProvider
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CertificateStore $certificates,
        private readonly LocalXmlPreparer $preparer,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function signsLocally(): bool
    {
        return true;
    }

    public function prepare(EcfDocument $document, EcfDocumentData $data): void
    {
        $this->preparer->prepare($document, $data, $this->certificates->active());
    }

    public function submit(EcfDocument $document): EcfSubmissionResult
    {
        $outcome = $this->outcome();
        if ($outcome === 'unavailable') {
            throw new EcfException('La DGII no está disponible (simulado).');
        }

        if ($document->isRfce()) {
            return $outcome === 'in_process'
                ? $this->result(EcfStatus::InProcess, null, 3)
                : $this->finalResult($outcome, null);
        }

        return $outcome === 'in_process'
            ? $this->result(EcfStatus::Sent, 'FAKE-'.Str::uuid(), null)
            : $this->finalResult($outcome, 'FAKE-'.Str::uuid());
    }

    public function poll(EcfDocument $document): EcfSubmissionResult
    {
        $outcome = $this->outcome();

        return $this->finalResult(in_array($outcome, ['accepted', 'conditionally_accepted', 'rejected'], true) ? $outcome : 'accepted', $document->track_id);
    }

    public function annul(EcfAnnulment $annulment, string $signedXml): EcfSubmissionResult
    {
        return $this->result(EcfStatus::Accepted, null, 1);
    }

    private function outcome(): string
    {
        return (string) $this->settings->get('ecf.fake.outcome', 'accepted');
    }

    private function finalResult(string $outcome, ?string $trackId): EcfSubmissionResult
    {
        return match ($outcome) {
            'rejected' => $this->result(EcfStatus::Rejected, $trackId, 2, [['codigo' => '0', 'valor' => 'Rechazo simulado']]),
            'conditionally_accepted' => $this->result(EcfStatus::ConditionallyAccepted, $trackId, 4),
            default => $this->result(EcfStatus::Accepted, $trackId, 1),
        };
    }

    /**
     * @param  list<array{codigo: string, valor: string}>  $messages
     */
    private function result(EcfStatus $status, ?string $trackId, ?int $code, array $messages = []): EcfSubmissionResult
    {
        return new EcfSubmissionResult(
            status: $status,
            trackId: $trackId,
            code: $code,
            messages: $messages,
            raw: ['fake' => true],
            sequenceUsed: true,
            httpStatus: 200,
        );
    }
}
