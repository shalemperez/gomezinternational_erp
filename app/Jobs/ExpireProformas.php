<?php

namespace App\Jobs;

use App\Enums\ProformaStatus;
use App\Models\Proforma;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

#[Tries(1)]
class ExpireProformas implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $expired = Proforma::query()->where('status', ProformaStatus::Sent->value)->whereDate('valid_until', '<', now()->toDateString())->get();

        foreach ($expired as $proforma) {
            $proforma->update(['status' => ProformaStatus::Expired]);
            activity('proformas')->performedOn($proforma)->event('expired')->log("Proforma {$proforma->number} vencida");
        }
    }
}
