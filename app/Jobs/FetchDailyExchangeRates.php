<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ExchangeRateFetchFailed;
use App\Services\Finance\ExchangeRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Notification;
use Throwable;

#[Tries(3)]
#[Backoff([60, 300, 900])]
class FetchDailyExchangeRates implements ShouldQueue
{
    use Queueable;

    public function handle(ExchangeRateService $service): void
    {
        $service->fetchToday();
    }

    public function failed(?Throwable $e): void
    {
        $message = $e?->getMessage() ?? 'Error desconocido';
        app(ExchangeRateService::class)->recordFailure($message);
        $users = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can('finance.exchange-rates.manage'));
        Notification::send($users, new ExchangeRateFetchFailed($message));
    }
}
