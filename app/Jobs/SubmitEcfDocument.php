<?php

namespace App\Jobs;

use App\Enums\EcfStatus;
use App\Models\EcfDocument;
use App\Services\Ecf\EcfService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[Tries(1)]
class SubmitEcfDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $uniqueFor = 600;

    /** A DGII call chain (seed, validate, post, 401 refresh) can take up to 4 × 30 s. */
    public int $timeout = 150;

    public function __construct(public EcfDocument $document) {}

    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    public function handle(EcfService $ecf): void
    {
        $this->document->refresh();

        if (! in_array($this->document->status, [EcfStatus::Pending, EcfStatus::Signed], true)) {
            return;
        }

        try {
            $knownByProvider = $this->document->attempts > 0 && $ecf->lookup($this->document);
            if (! $knownByProvider) {
                $ecf->submit($this->document);
            }
        } catch (Throwable $exception) {
            $ecf->recordFailure($this->document, $exception, $this->document->isRfce() ? 'rfce' : 'send');

            if ($this->document->attempts >= (int) config('ecf.max_attempts')) {
                $this->document->update(['next_attempt_at' => null]);
                activity('ecf_documents')->performedOn($this->document)->event('retries_exhausted')
                    ->withProperties(['attempts' => $this->document->attempts])
                    ->log('Reintentos agotados');
            }

            return;
        }

        if (in_array($this->document->status, [EcfStatus::Sent, EcfStatus::InProcess], true)) {
            PollEcfStatus::dispatch($this->document)->delay(now()->addSeconds((int) config('ecf.poll_delay_seconds')));
        }
    }
}
