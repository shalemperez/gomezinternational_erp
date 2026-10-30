<?php

use App\Models\AdditionalTax;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Services\Documents\DocumentTotalsCalculator;
use App\Support\Documents\LineInput;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('line math follows ARCH §4 order with HALF_UP rounding', function () {
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $totals = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '3', '100.3333', '10', null)]);
    $line = $totals->lines[0];

    expect($line->lineSubtotal)->toBe('301.00')      // 3 × 100.3333 = 300.9999 → 301.00
        ->and($line->discountAmount)->toBe('30.10')  // 301.00 × 10 % = 30.10
        ->and($line->taxableBase)->toBe('270.90')
        ->and($line->itbisAmount)->toBe('48.76')     // 270.90 × 18 % = 48.762 → 48.76
        ->and($line->lineTotal)->toBe('319.66')
        ->and($totals->subtotal)->toBe('301.00')->and($totals->discountTotal)->toBe('30.10')
        ->and($totals->taxableTotal)->toBe('270.90')->and($totals->itbisTotal)->toBe('48.76')->and($totals->total)->toBe('319.66');
});

test('additional taxes feed the itbis base only when flagged', function () {
    $isc = AdditionalTax::factory()->create(['code' => 'ISC-ADV', 'calculation_type' => 'percent_of_base', 'rate' => '10', 'included_in_itbis_base' => true]);
    $other = AdditionalTax::factory()->create(['code' => 'OTRO', 'calculation_type' => 'fixed_per_unit', 'rate' => null, 'amount' => '5', 'included_in_itbis_base' => false]);
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $product->additionalTaxes()->sync([$isc->id, $other->id]);

    $line = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '2', '100', '0', null)])->lines[0];

    expect($line->taxableBase)->toBe('200.00')
        ->and($line->additionalTaxes)->toBe([['code' => 'ISC-ADV', 'name' => $isc->name, 'amount' => '20.00'], ['code' => 'OTRO', 'name' => $other->name, 'amount' => '10.00']])
        ->and($line->additionalTaxTotal)->toBe('30.00')
        ->and($line->itbisAmount)->toBe('39.60')     // (200 + 20) × 18 %
        ->and($line->lineTotal)->toBe('269.60');
});

test('exempt products carry no itbis and document totals are sums of rounded lines', function () {
    $a = Product::factory()->create(['itbis_rate' => '0.00']);
    $b = Product::factory()->create(['itbis_rate' => '16.00']);
    $totals = app(DocumentTotalsCalculator::class)->compute([new LineInput($a->id, '1', '0.005', '0', null), new LineInput($b->id, '1', '0.005', '0', null)]);
    expect($totals->lines[0]->itbisAmount)->toBe('0.00')->and($totals->lines[0]->lineSubtotal)->toBe('0.01')
        ->and($totals->subtotal)->toBe('0.02')->and($totals->itbisTotal)->toBe('0.00')->and($totals->total)->toBe('0.02');
});

test('priceFor uses the client list then the default list then zero', function () {
    $product = Product::factory()->create();
    $default = PriceList::factory()->create(['is_default' => true]);
    $special = PriceList::factory()->create();
    PriceListItem::factory()->create(['price_list_id' => $default->id, 'product_id' => $product->id, 'price' => '50.0000']);
    PriceListItem::factory()->create(['price_list_id' => $special->id, 'product_id' => $product->id, 'price' => '45.0000']);
    $client = Client::factory()->create(['price_list_id' => $special->id]);
    $calc = app(DocumentTotalsCalculator::class);

    expect($calc->priceFor($product, $client))->toBe('45.0000')
        ->and($calc->priceFor($product, Client::factory()->create(['price_list_id' => null])))->toBe('50.0000')
        ->and($calc->priceFor(Product::factory()->create(), null))->toBe('0.0000');
});

test('description defaults to the product name and quantities keep four decimals', function () {
    $product = Product::factory()->create(['name' => 'Ron 750']);
    $line = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '1.5', '10', '0', null)])->lines[0];
    expect($line->description)->toBe('Ron 750')->and($line->quantity)->toBe('1.5000')->and($line->unitPrice)->toBe('10.0000');
});

test('an explicit itbis rate overrides the product rate', function () {
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $line = app(DocumentTotalsCalculator::class)->compute([new LineInput($product->id, '1', '100', '0', null, '16.00')])->lines[0];
    expect($line->itbisRate)->toBe('16.00')->and($line->itbisAmount)->toBe('16.00')->and($line->lineTotal)->toBe('116.00');
});
