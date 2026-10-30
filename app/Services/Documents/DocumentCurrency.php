<?php

namespace App\Services\Documents;

use App\Models\Currency;
use App\Services\Finance\ExchangeRateService;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class DocumentCurrency
{
    /** @return array{currency: Currency, exchange_rate: string} */
    public static function resolve(int $currencyId, CarbonInterface $date): array
    {
        $currency = Currency::query()->whereKey($currencyId)->firstOrFail();
        if ($currency->is_base) {
            return ['currency' => $currency, 'exchange_rate' => '1.000000'];
        }

        $rate = app(ExchangeRateService::class)->rateFor($currency, $date)
            ?? throw ValidationException::withMessages(['currency_id' => "No hay tasa de cambio registrada para {$currency->code} en {$date->format('d/m/Y')} ni fechas anteriores."]);

        return ['currency' => $currency, 'exchange_rate' => Decimal::rate((string) $rate->rate)];
    }
}
