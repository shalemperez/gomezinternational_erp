<?php

namespace App\Jobs;

use App\Enums\EcfStatus;
use App\Models\EcfDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

#[Tries(1)]
class RetryPendingEcfDocuments implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $dueSubmissions = EcfDocument::query()->readyToSubmit()
            ->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())->get();
        $firstSubmissions = EcfDocument::query()->where('status', EcfStatus::Signed->value)->where('attempts', 0)
            ->where('created_at', '<=', now()->subMinutes(2))->get();

        foreach ($dueSubmissions->merge($firstSubmissions) as $document) {
            SubmitEcfDocument::dispatch($document);
        }

        $dueStatusChecks = EcfDocument::query()->awaitingResult()
            ->where(fn (Builder $due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->get();

        foreach ($dueStatusChecks as $document) {
            PollEcfStatus::dispatch($document);
        }

        $submitted = $dueSubmissions->count() + $firstSubmissions->count();
        $polled = $dueStatusChecks->count();
        if ($submitted > 0 || $polled > 0) {
            activity('ecf_documents')->event('retry_sweep')->withProperties(['submitted' => $submitted, 'polled' => $polled])
                ->log("Barrido de e-CF: {$submitted} reenviados, {$polled} consultados");
        }
    }
}
