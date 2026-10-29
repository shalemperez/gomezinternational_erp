<?php

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Settings;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new CurrencySeeder)->run());

test('index requires view permission and sales can view but not manage', function () {
    actingAsRole('warehouse');
    $this->get(route('finance.exchange-rates.index'))->assertForbidden();

    actingAsRole('sales');
    $this->get(route('finance.exchange-rates.index'))->assertOk()->assertInertia(fn ($p) => $p->component('finance/exchange-rates/index')->where('canManage', false)->has('currencies', 1));
    $this->post(route('finance.exchange-rates.store'), [])->assertForbidden();
});

test('manager registers a manual rate, re-posting the same date updates it, and it is logged', function () {
    $user = actingAsRole('accounting');
    $usd = Currency::where('code', 'USD')->first();

    $this->post(route('finance.exchange-rates.store'), ['currency_id' => $usd->id, 'rate_date' => '2026-10-29', 'rate' => '58.1234567', 'notes' => ''])->assertSessionHasErrors(['rate']);
    $this->post(route('finance.exchange-rates.store'), ['currency_id' => Currency::base()->id, 'rate_date' => '2026-10-29', 'rate' => '1'])->assertSessionHasErrors(['currency_id']);

    $this->post(route('finance.exchange-rates.store'), ['currency_id' => $usd->id, 'rate_date' => '2026-10-29', 'rate' => '58.12', 'notes' => 'BCRD'])
        ->assertRedirect(route('finance.exchange-rates.index'))->assertSessionHas('success', 'Tasa registrada');
    $this->post(route('finance.exchange-rates.store'), ['currency_id' => $usd->id, 'rate_date' => '2026-10-29', 'rate' => '58.20']);

    $rate = ExchangeRate::where('currency_id', $usd->id)->whereDate('rate_date', '2026-10-29')->firstOrFail();
    expect(ExchangeRate::count())->toBe(1)->and($rate->rate)->toBe('58.200000')->and($rate->created_by)->toBe($user->id)
        ->and(Activity::inLog('exchange_rates')->where('subject_id', $rate->id)->count())->toBe(2);
});

test('correcting a fetched rate marks it manual', function () {
    actingAsRole('owner');
    $rate = ExchangeRate::factory()->create(['currency_id' => Currency::where('code', 'USD')->first()->id, 'source' => 'http_json', 'fetched_at' => now()]);

    $this->put(route('finance.exchange-rates.update', $rate), ['rate' => '59', 'notes' => 'corregida'])->assertRedirect(route('finance.exchange-rates.index'));
    expect($rate->fresh()->rate)->toBe('59.000000')->and($rate->fresh()->source->value)->toBe('manual');
});

test('fetch now with manual source shows an error', function () {
    actingAsRole('owner');
    $this->post(route('finance.exchange-rates.fetch'))->assertRedirect()->assertSessionHas('error', 'La fuente configurada es manual; no hay nada que obtener');
});

test('fetch now failure is logged and stored', function () {
    actingAsRole('owner');
    $settings = app(Settings::class);
    $settings->set('exchange_rates.source', 'http_json');
    $settings->set('exchange_rates.http_json.url', 'https://rates.test/{currency}/{date}');
    $settings->set('exchange_rates.http_json.json_path', 'rate');
    Http::fake(['rates.test/*' => Http::response('error', 500)]);

    $this->post(route('finance.exchange-rates.fetch'))->assertRedirect()->assertSessionHas('error');

    expect($settings->get('exchange_rates.last_fetch_error'))->not->toBeNull()
        ->and(Activity::inLog('exchange_rates')->where('event', 'fetch_failed')->exists())->toBeTrue();
});
