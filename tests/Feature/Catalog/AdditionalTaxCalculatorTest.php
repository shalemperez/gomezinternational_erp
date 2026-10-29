<?php

use App\Models\AdditionalTax;
use App\Services\Catalog\AdditionalTaxCalculator;

test('percent of base', fn () => expect(app(AdditionalTaxCalculator::class)->forLine(new AdditionalTax(['calculation_type' => 'percent_of_base', 'rate' => '10.0000']), '3', '1000.00', null, null))->toBe('100.00'));

test('fixed per unit', fn () => expect(app(AdditionalTaxCalculator::class)->forLine(new AdditionalTax(['calculation_type' => 'fixed_per_unit', 'amount' => '25.5000']), '3', '0', null, null))->toBe('76.50'));

test('fixed per liter', fn () => expect(app(AdditionalTaxCalculator::class)->forLine(new AdditionalTax(['calculation_type' => 'fixed_per_liter', 'amount' => '10.0000']), '2', '0', '0.7500', null))->toBe('15.00'));

test('fixed per degree liter', fn () => expect(app(AdditionalTaxCalculator::class)->forLine(new AdditionalTax(['calculation_type' => 'fixed_per_degree_liter', 'amount' => '600.0000']), '2', '0', '0.7500', '40.00'))->toBe('360.00'));

test('missing volume throws', fn () => expect(fn () => app(AdditionalTaxCalculator::class)->forLine(new AdditionalTax(['calculation_type' => 'fixed_per_liter', 'amount' => '1']), '1', '0', null, null))->toThrow(InvalidArgumentException::class, 'El producto no tiene volumen en litros'));
