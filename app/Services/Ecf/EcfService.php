<?php

namespace App\Services\Ecf;

use App\Contracts\EcfProvider;
use App\Enums\EcfEnvironment;
use App\Enums\EcfStatus;
use App\Exceptions\EcfException;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\EcfDocument;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\EcfRejected;
use App\Services\Ecf\Providers\DgiiDirectProvider;
use App\Services\Ecf\Providers\FakeEcfProvider;
use App\Services\Settings;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfSubmissionResult;
use App\Support\Fiscal\FiscalNumber;
use App\Support\FiscalId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Orchestrates one electronic document: build, sign, store, submit, poll and retry. Actions and jobs talk to the
 * DGII only through the EcfProvider resolved here.
 */
class EcfService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EcfDocumentBuilder $builder,
        private readonly EcfStorage $storage,
    ) {}

    public function environment(): EcfEnvironment
    {
        return EcfEnvironment::from((string) $this->settings->get('ecf.environment', 'test'));
    }

    public function rfceThreshold(): string
    {
        return (string) $this->settings->get('ecf.rfce_threshold', config('ecf.rfce_threshold'));
    }

    /** @throws EcfException when the configured provider is not supported */
    public function provider(): EcfProvider
    {
        $name = (string) $this->settings->get('ecf.provider', 'fake');

        return match ($name) {
            'fake' => app(FakeEcfProvider::class),
            'dgii' => app(DgiiDirectProvider::class),
            default => throw new EcfException("Proveedor e-CF no soportado: {$name}"),
        };
    }

    /** Builds and signs the e-CF for a sales document; the e-NCF is already reserved by the caller. */
    public function create(Invoice|CreditNote|DebitNote $document, FiscalNumber $fiscal, ?User $actor): EcfDocument
    {
        $data = $this->dataFor($document, $fiscal);
        $provider = $this->provider();

        $ecfDocument = EcfDocument::query()->create([
            'document_type' => $document::class,
            'document_id' => $document->getKey(),
            'ncf_type' => $fiscal->type,
            'encf' => $fiscal->ncf,
            'environment' => $this->environment(),
            'provider' => $provider->name(),
            'flow' => $data->requiresRfce($this->rfceThreshold()) ? 'rfce' : 'ecf',
            'status' => EcfStatus::Pending,
            'ncf_sequence_id' => $fiscal->sequenceId,
            'created_by' => $actor?->id,
        ]);

        $provider->prepare($ecfDocument, $data);
        $ecfDocument->save();

        activity('ecf_documents')->causedBy($actor)->performedOn($ecfDocument)->event('signed')
            ->withProperties(['encf' => $ecfDocument->encf, 'flow' => $ecfDocument->flow, 'security_code' => $ecfDocument->security_code])
            ->log('Documento firmado');

        return $ecfDocument;
    }

    /**
     * The attempt is counted before the provider call so a failed call still counts (see recordFailure).
     *
     * @throws EcfException when the document was already sent or the provider is unavailable
     */
    public function submit(EcfDocument $ecfDocument): EcfDocument
    {
        if (! in_array($ecfDocument->status, [EcfStatus::Pending, EcfStatus::Signed], true)) {
            throw new EcfException("El e-CF {$ecfDocument->encf} ya fue enviado");
        }

        $ecfDocument->forceFill(['attempts' => $ecfDocument->attempts + 1, 'last_attempt_at' => now()])->save();

        $result = $this->provider()->submit($ecfDocument);
        $this->applyResult($ecfDocument, $result, $ecfDocument->isRfce() ? 'rfce' : 'send');

        return $ecfDocument;
    }

    /** @throws EcfException when the document is not waiting for a DGII result */
    public function poll(EcfDocument $ecfDocument): EcfDocument
    {
        if (! in_array($ecfDocument->status, [EcfStatus::Sent, EcfStatus::InProcess], true)) {
            throw new EcfException("El e-CF {$ecfDocument->encf} no está esperando resultado de la DGII");
        }

        $this->applyResult($ecfDocument, $this->provider()->poll($ecfDocument), 'poll');

        return $ecfDocument;
    }

    public function applyResult(EcfDocument $ecfDocument, EcfSubmissionResult $result, string $kind): void
    {
        $ecfDocument->transmissions()->create([
            'kind' => $kind,
            'environment' => $ecfDocument->environment,
            'http_status' => $result->httpStatus,
            'response' => $result->raw,
            'succeeded' => true,
        ]);

        if ($result->signedXml !== null && $ecfDocument->xml_path === null) {
            $fileName = FiscalId::normalize((string) $this->settings->get('company.rnc', '')).$ecfDocument->encf.'.xml';
            $ecfDocument->xml_path = $this->storage->putNew($this->storage->documentDir($ecfDocument).'/'.$fileName, $result->signedXml);
        }
        $ecfDocument->security_code ??= $result->securityCode;
        $ecfDocument->signed_at ??= $result->signedAt;
        $ecfDocument->qr_url ??= $result->qrUrl;

        $ecfDocument->status = $result->status;
        $ecfDocument->track_id = $result->trackId ?? $ecfDocument->track_id;

        if (in_array($result->status, [EcfStatus::Sent, EcfStatus::InProcess], true)) {
            $ecfDocument->next_attempt_at = now()->addSeconds((int) config('ecf.poll_delay_seconds'));
        } else {
            $ecfDocument->dgii_code = $result->code;
            $ecfDocument->dgii_messages = $result->messages;
            $ecfDocument->next_attempt_at = null;
            if ($result->status === EcfStatus::Rejected) {
                $ecfDocument->rejected_at = now();
            } else {
                $ecfDocument->accepted_at = now();
            }
        }

        $ecfDocument->save();

        activity('ecf_documents')->performedOn($ecfDocument)
            ->event($result->status === EcfStatus::InProcess ? 'polled' : $result->status->value)
            ->withProperties(['track_id' => $ecfDocument->track_id, 'code' => $result->code, 'messages' => $result->messages])
            ->log($result->status->label());

        if ($result->status === EcfStatus::Rejected) {
            Notification::send($this->alertRecipients(), new EcfRejected($ecfDocument));
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function alertRecipients(): Collection
    {
        return User::query()->where('is_active', true)->get()->filter(fn (User $user) => $user->can('fiscal.ecf.alerts'));
    }

    /**
     * Stores the failed attempt and schedules the next one with the configured backoff (the last step repeats).
     */
    public function recordFailure(EcfDocument $ecfDocument, Throwable $exception, string $kind): void
    {
        $message = mb_substr($exception->getMessage(), 0, 500);
        $ecfDocument->transmissions()->create([
            'kind' => $kind,
            'environment' => $ecfDocument->environment,
            'succeeded' => false,
            'error' => $message,
        ]);

        $backoff = config('ecf.retry_backoff_minutes');
        $step = min(max($ecfDocument->attempts - 1, 0), count($backoff) - 1);
        $ecfDocument->update([
            'last_error' => $message,
            'next_attempt_at' => now()->addMinutes($backoff[$step]),
        ]);

        activity('ecf_documents')->performedOn($ecfDocument)->event('retried')
            ->withProperties(['attempt' => $ecfDocument->attempts, 'error' => $message])
            ->log('Reintento programado');
    }

    private function dataFor(Invoice|CreditNote|DebitNote $document, FiscalNumber $fiscal): EcfDocumentData
    {
        if ($document instanceof Invoice) {
            return $this->builder->fromInvoice($document, $fiscal->type, $fiscal->ncf, $fiscal->expiresOn);
        }

        if ($document instanceof CreditNote) {
            return $this->builder->fromCreditNote($document, $fiscal->type, $fiscal->ncf, $fiscal->expiresOn);
        }

        return $this->builder->fromDebitNote($document, $fiscal->type, $fiscal->ncf, $fiscal->expiresOn);
    }
}
