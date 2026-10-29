<?php

namespace App\Services\Finance;

use App\Contracts\ExchangeRateSource;
use App\Exceptions\MissingExchangeRateException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Settings;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ExchangeRateService
{
    public function __construct(private readonly Settings $settings) {}

    public function source(): ExchangeRateSource
    {
        return match ($this->settings->get('exchange_rates.source', 'manual')) {
            'http_json' => app(HttpJsonExchangeRateSource::class),
            default => app(ManualExchangeRateSource::class),
        };
    }

    public function rateFor(Currency|string $currency, CarbonInterface $date): ?ExchangeRate
    {
        $currency = $this->resolve($currency);

        return ExchangeRate::query()->where('currency_id', $currency->id)->whereDate('rate_date', '<=', $date->toDateString())->orderByDesc('rate_date')->first();
    }

    public function convertToBase(string $amount, Currency|string $currency, CarbonInterface $date): string
    {
        $currency = $this->resolve($currency);
        if ($currency->is_base) {
            return Decimal::money($amount);
        }
        $rate = $this->rateFor($currency, $date)
            ?? throw new MissingExchangeRateException("No hay tasa de cambio registrada para {$currency->code} en {$date->format('d/m/Y')} ni fechas anteriores.");

        return Decimal::mul($amount, (string) $rate->rate, Decimal::MONEY);
    }

    /** @return list<ExchangeRate> */
    public function fetchToday(): array
    {
        $today = CarbonImmutable::today();
        $source = $this->source();
        $stored = [];
        foreach (Currency::query()->active()->where('is_base', false)->get() as $currency) {
            if (ExchangeRate::query()->where('currency_id', $currency->id)->whereDate('rate_date', $today->toDateString())->exists()) {
                continue; // never overwrite a rate already registered for today (manual corrections win)
            }
            $fetched = $source->fetch($currency, $today);
            if ($fetched === null) {
                continue;
            }
            $stored[] = ExchangeRate::create(['currency_id' => $currency->id, 'rate_date' => $today->toDateString(), 'rate' => $fetched->rate, 'source' => $source->name(), 'fetched_at' => now(), 'created_by' => null, 'notes' => null]);
        }
        $this->settings->set('exchange_rates.last_fetch_error', null);
        activity('exchange_rates')->event('fetched')->withProperties(['count' => count($stored), 'source' => $source->name()])->log('Tasas de cambio obtenidas: '.count($stored));

        return $stored;
    }

    /** Records a failed automatic fetch (used by the job and by the manual "fetch now" action). */
    public function recordFailure(string $message): void
    {
        $this->settings->set('exchange_rates.last_fetch_error', ['at' => now()->toIso8601String(), 'message' => $message]);
        activity('exchange_rates')->event('fetch_failed')->withProperties(['message' => $message])->log('Falló la obtención automática de tasas');
    }

    private function resolve(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::query()->where('code', $currency)->firstOrFail();
    }
}
