<?php

use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new CurrencySeeder)->run());

test('index requires permission', function () {
    actingAsRole('sales');
    $this->get(route('finance.currencies.index'))->assertForbidden();
    actingAsRole('owner');
    $this->get(route('finance.currencies.index'))->assertOk()->assertInertia(fn ($p) => $p->component('finance/currencies/index')->has('rows.data', 2));
});

test('store validates and creates a currency', function () {
    actingAsRole('owner');
    $this->post(route('finance.currencies.store'), ['code' => 'eur', 'name' => '', 'symbol' => '', 'decimal_places' => 9])->assertSessionHasErrors(['code', 'name', 'symbol', 'decimal_places']);
    $this->post(route('finance.currencies.store'), validCurrencyPayload())->assertRedirect(route('finance.currencies.index'))->assertSessionHas('success', 'Moneda creada');
    expect(Currency::where('code', 'EUR')->first()->is_active)->toBeTrue();
});

test('base currency cannot be deactivated but can be renamed', function () {
    actingAsRole('owner');
    $dop = Currency::base();
    $this->put(route('finance.currencies.update', $dop), ['name' => 'Peso', 'symbol' => 'RD$', 'decimal_places' => 2])
        ->assertSessionHasErrors(['is_active' => 'La moneda base no se puede desactivar.']);
    $this->put(route('finance.currencies.update', $dop), ['name' => 'Peso', 'symbol' => 'RD$', 'decimal_places' => 2, 'is_active' => 'on'])
        ->assertRedirect(route('finance.currencies.index'));
    expect($dop->fresh()->name)->toBe('Peso');
});

test('currency changes are logged', function () {
    actingAsRole('owner');
    $this->post(route('finance.currencies.store'), validCurrencyPayload());
    $eur = Currency::where('code', 'EUR')->firstOrFail();
    $this->put(route('finance.currencies.update', $eur), ['name' => 'Euro europeo', 'symbol' => '€', 'decimal_places' => 2, 'is_active' => 'on']);

    $updated = Activity::inLog('currencies')->where('event', 'updated')->where('subject_id', $eur->id)->latest('id')->firstOrFail();
    expect(Activity::inLog('currencies')->where('event', 'created')->where('subject_id', $eur->id)->exists())->toBeTrue()
        ->and($updated->attribute_changes['old']['name'])->toBe('Euro')
        ->and($updated->attribute_changes['attributes']['name'])->toBe('Euro europeo');
});
