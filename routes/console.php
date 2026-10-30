<?php

use App\Jobs\CheckNcfSequences;
use App\Jobs\ExpireProformas;
use App\Jobs\FetchDailyExchangeRates;
use App\Services\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new FetchDailyExchangeRates)
    ->dailyAt(config('erp.exchange_rates.fetch_time'))
    ->when(fn () => app(Settings::class)->get('exchange_rates.source', 'manual') !== 'manual');

Schedule::job(new CheckNcfSequences)->dailyAt('07:30');

Schedule::job(new ExpireProformas)->dailyAt('00:10');
