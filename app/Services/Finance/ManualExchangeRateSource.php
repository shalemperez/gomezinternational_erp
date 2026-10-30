<?php

namespace App\Services\Finance;

use App\Contracts\ExchangeRateSource;
use App\Models\Currency;
use App\Support\FetchedRate;
use Carbon\CarbonImmutable;

class ManualExchangeRateSource implements ExchangeRateSource
{
    public function name(): string
    {
        return 'manual';
    }

    public function fetch(Currency $currency, CarbonImmutable $date): ?FetchedRate
    {
        return null;
    }
}
