<?php

namespace App\Services\Finance;

use App\Contracts\ExchangeRateSource;
use App\Models\Currency;
use App\Services\Settings;
use App\Support\FetchedRate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpJsonExchangeRateSource implements ExchangeRateSource
{
    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return 'http_json';
    }

    public function fetch(Currency $currency, CarbonImmutable $date): ?FetchedRate
    {
        $url = (string) $this->settings->get('exchange_rates.http_json.url', '');
        $path = (string) $this->settings->get('exchange_rates.http_json.json_path', '');
        if ($url === '' || $path === '') {
            throw new RuntimeException('La fuente externa no está configurada (URL y ruta JSON).');
        }
        $url = str_replace(['{currency}', '{date}'], [$currency->code, $date->format('Y-m-d')], $url);
        $json = Http::timeout(15)->acceptJson()->get($url)->throw()->json();
        $value = data_get($json, $path);
        if (! is_numeric($value)) {
            throw new RuntimeException("La respuesta no contiene una tasa numérica en la ruta '{$path}'.");
        }

        return new FetchedRate(Decimal::rate((string) $value), is_array($json) ? $json : []);
    }
}
