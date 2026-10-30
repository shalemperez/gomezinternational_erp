<?php

use App\Models\Product;

test('preview endpoint returns computed lines and totals and validates input', function () {
    actingAsRole('sales');
    $product = Product::factory()->create(['itbis_rate' => '18.00']);

    $this->postJson(route('sales.totals.preview'), ['lines' => [['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']]])
        ->assertOk()->assertJsonPath('totals.total', '236.00')->assertJsonPath('lines.0.itbis_amount', '36.00');

    $this->postJson(route('sales.totals.preview'), ['lines' => [['product_id' => 999999, 'quantity' => '-1', 'unit_price' => 'x']]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.product_id', 'lines.0.quantity', 'lines.0.unit_price']);
});
