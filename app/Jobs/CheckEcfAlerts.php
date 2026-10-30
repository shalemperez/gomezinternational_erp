<?php

namespace App\Jobs;

use App\Enums\EcfStatus;
use App\Models\EcfDocument;
use App\Notifications\EcfAlert;
use App\Services\Ecf\CertificateStore;
use App\Services\Ecf\EcfService;
use Carbon\CarbonImmutable;
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
        $certificateAlert = $this->certificateAlert();

        $pending = array_values(EcfDocument::query()->with('document')
            ->whereIn('status', [EcfStatus::Pending->value, EcfStatus::Signed->value, EcfStatus::Sent->value, EcfStatus::InProcess->value])
            ->where('created_at', '<=', now()->subHours((int) config('ecf.pending_alert_hours')))
            ->get()
            ->map(fn (EcfDocument $document) => [
                'encf' => $document->encf,
                'number' => (string) $document->document?->getAttribute('number'),
                'created_at' => $document->created_at->toIso8601String(),
                'urgent' => $document->created_at->lte(now()->subHours(48)),
            ])->all());

        $rejected = array_values(EcfDocument::query()->with('document')->where('status', EcfStatus::Rejected->value)->where('rejected_at', '>=', now()->subHours(24))
            ->get()
            ->map(fn (EcfDocument $document) => [
                'encf' => $document->encf,
                'number' => (string) $document->document?->getAttribute('number'),
                'message' => (string) ($document->dgii_messages[0]['valor'] ?? ''),
            ])->all());

        activity('ecf_documents')->event('alerts_checked')->withProperties(['pending' => count($pending), 'rejected' => count($rejected), 'certificate_days_left' => $certificateAlert['days_left'] ?? null])
            ->log('Revisión de alertas de e-CF: '.count($pending).' pendientes, '.count($rejected).' rechazados');
        if ($pending === [] && $rejected === [] && $certificateAlert === null) {
            return;
        }

        try {
            Notification::send($ecf->alertRecipients(), new EcfAlert($pending, $rejected, $certificateAlert));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** @return array{name: string, expires_on: string, days_left: int, expired: bool}|null */
    private function certificateAlert(): ?array
    {
        $certificate = app(CertificateStore::class)->current();
        if ($certificate === null || $certificate->valid_to->gt(now()->addDays(30))) {
            return null;
        }

        $validTo = CarbonImmutable::instance($certificate->valid_to)->setTimezone(config('ecf.timezone'));

        return [
            'name' => $certificate->name,
            'expires_on' => $validTo->format('d/m/Y'),
            'days_left' => (int) CarbonImmutable::now(config('ecf.timezone'))->startOfDay()->diffInDays($validTo->startOfDay(), false),
            'expired' => $certificate->isExpired(),
        ];
    }
}
