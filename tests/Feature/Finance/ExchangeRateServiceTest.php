<?php

use App\Exceptions\MissingExchangeRateException;
use App\Jobs\FetchDailyExchangeRates;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Notifications\ExchangeRateFetchFailed;
use App\Services\Finance\ExchangeRateService;
use App\Services\Settings;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new CurrencySeeder)->run());

function configureHttpSource(string $path): void
{
    $settings = app(Settings::class);
    $settings->set('exchange_rates.source', 'http_json');
    $settings->set('exchange_rates.http_json.url', 'https://rates.test/{currency}/{date}');
    $settings->set('exchange_rates.http_json.json_path', $path);
}

test('seeder creates base DOP and USD', function () {
    expect(Currency::base()->code)->toBe('DOP')->and(Currency::where('code', 'USD')->exists())->toBeTrue();
});

test('rateFor uses the exact date or the most recent earlier one', function () {
    $usd = Currency::where('code', 'USD')->first();
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => '2026-10-01', 'rate' => '58.000000']);
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => '2026-10-10', 'rate' => '58.500000']);
    $s = app(ExchangeRateService::class);

    expect($s->rateFor('USD', Carbon::parse('2026-10-10'))->rate)->toBe('58.500000')
        ->and($s->rateFor('USD', Carbon::parse('2026-10-05'))->rate)->toBe('58.000000')
        ->and($s->rateFor('USD', Carbon::parse('2026-09-30')))->toBeNull()
        ->and($s->convertToBase('100', 'USD', Carbon::parse('2026-10-20')))->toBe('5850.00')
        ->and($s->convertToBase('100.004', 'DOP', Carbon::parse('2026-10-20')))->toBe('100.00');
});

test('convertToBase rounds half up and throws when no rate exists', function () {
    $usd = Currency::where('code', 'USD')->first();
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => '2026-10-01', 'rate' => '58.123456']);
    $s = app(ExchangeRateService::class);

    expect($s->convertToBase('100', $usd, Carbon::parse('2026-10-01')))->toBe('5812.35');
    expect(fn () => $s->convertToBase('1', 'USD', Carbon::parse('2026-01-01')))
        ->toThrow(MissingExchangeRateException::class, 'No hay tasa de cambio registrada para USD en 01/01/2026 ni fechas anteriores.');
});

test('manual source fetches nothing', function () {
    expect(app(ExchangeRateService::class)->fetchToday())->toBe([])->and(ExchangeRate::count())->toBe(0);
});

test('http json source stores today rate from the configured path and logs it', function () {
    configureHttpSource('data.sell');
    Http::fake(['rates.test/USD/'.now()->toDateString() => Http::response(['data' => ['sell' => '58.50']])]);

    $stored = app(ExchangeRateService::class)->fetchToday();

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->rate)->toBe('58.500000')
        ->and($stored[0]->source->value)->toBe('http_json')
        ->and($stored[0]->fetched_at)->not->toBeNull()
        ->and(Activity::inLog('exchange_rates')->where('event', 'fetched')->latest('id')->first()->properties['count'])->toBe(1);
});

test('scheduled fetch does not overwrite an existing manual rate', function () {
    $usd = Currency::where('code', 'USD')->first();
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->toDateString(), 'rate' => '57.000000', 'source' => 'manual']);
    configureHttpSource('rate');
    Http::fake(['rates.test/*' => Http::response(['rate' => 60])]);

    expect(app(ExchangeRateService::class)->fetchToday())->toBe([])
        ->and(ExchangeRate::where('currency_id', $usd->id)->whereDate('rate_date', now()->toDateString())->first()->rate)->toBe('57.000000');
});

test('http json source with a wrong path throws a spanish error', function () {
    configureHttpSource('nope');
    Http::fake(['rates.test/*' => Http::response(['rate' => '58'])]);

    expect(fn () => app(ExchangeRateService::class)->fetchToday())->toThrow(RuntimeException::class, "La respuesta no contiene una tasa numérica en la ruta 'nope'.");
});

test('job failure notifies managers and records and logs the error', function () {
    Notification::fake();
    $owner = actingAsRole('owner');
    $sales = User::factory()->create();
    $sales->assignRole('sales');

    (new FetchDailyExchangeRates)->failed(new RuntimeException('timeout'));

    Notification::assertSentTo($owner, ExchangeRateFetchFailed::class);
    Notification::assertNotSentTo($sales, ExchangeRateFetchFailed::class);
    expect(app(Settings::class)->get('exchange_rates.last_fetch_error')['message'])->toBe('timeout')
        ->and(Activity::inLog('exchange_rates')->where('event', 'fetch_failed')->exists())->toBeTrue();
});
