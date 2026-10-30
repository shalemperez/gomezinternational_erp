<?php

namespace App\Contracts;

use App\Models\Currency;
use App\Support\FetchedRate;
use Carbon\CarbonImmutable;

interface ExchangeRateSource
{
    public function name(): string;

    public function fetch(Currency $currency, CarbonImmutable $date): ?FetchedRate;
}
