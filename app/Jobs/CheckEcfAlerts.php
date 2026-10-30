<?php

namespace App\Jobs;

use App\Enums\EcfStatus;
use App\Models\EcfDocument;
use App\Notifications\EcfAlert;
use App\Services\Ecf\EcfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Notification;

#[Tries(1)]
class CheckEcfAlerts implements ShouldQueue
{
    use Queueable;

    public function handle(EcfService $ecf): void
    {
        $pending = array_values(EcfDocument::query()
            ->whereIn('status', [EcfStatus::Pending->value, EcfStatus::Signed->value, EcfStatus::Sent->value, EcfStatus::InProcess->value])
            ->where('created_at', '<=', now()->subHours((int) config('ecf.pending_alert_hours')))
            ->get()
            ->map(fn (EcfDocument $document) => [
                'encf' => $document->encf,
                'number' => (string) $document->document?->getAttribute('number'),
                'created_at' => $document->created_at->toIso8601String(),
                'urgent' => $document->created_at->lte(now()->subHours(48)),
            ])->all());

        $rejected = array_values(EcfDocument::query()->where('status', EcfStatus::Rejected->value)->where('rejected_at', '>=', now()->subHours(24))
            ->get()
            ->map(fn (EcfDocument $document) => [
                'encf' => $document->encf,
                'number' => (string) $document->document?->getAttribute('number'),
                'message' => (string) ($document->dgii_messages[0]['valor'] ?? ''),
            ])->all());

        activity('ecf_documents')->event('alerts_checked')->withProperties(['pending' => count($pending), 'rejected' => count($rejected)])
            ->log('Revisión de alertas de e-CF: '.count($pending).' pendientes, '.count($rejected).' rechazados');
        if ($pending === [] && $rejected === []) {
            return;
        }

        Notification::send($ecf->alertRecipients(), new EcfAlert($pending, $rejected));
    }
}
