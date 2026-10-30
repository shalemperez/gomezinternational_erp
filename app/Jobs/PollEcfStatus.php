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
class PollEcfStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public EcfDocument $document) {}

    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    public function handle(EcfService $ecf): void
    {
        $this->document->refresh();

        if (! in_array($this->document->status, [EcfStatus::Sent, EcfStatus::InProcess], true)) {
            return;
        }

        try {
            $ecf->poll($this->document);
        } catch (Throwable $exception) {
            $ecf->recordFailure($this->document, $exception, 'poll');
        }
    }
}
