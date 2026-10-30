<?php

use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\User;

test('search returns active products by sku or name with the client price', function () {
    actingAsRole('sales');
    $list = PriceList::factory()->create(['is_default' => true]);
    $p = Product::factory()->create(['sku' => 'RON-750', 'name' => 'Ron añejo']);
    Product::factory()->create(['sku' => 'OFF-1', 'name' => 'Ron viejo', 'is_active' => false]);
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => $p->id, 'price' => '150.0000']);
    $client = Client::factory()->create();

    $this->getJson(route('catalog.products.search', ['q' => 'ron', 'client_id' => $client->id]))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'RON-750')->assertJsonPath('data.0.unit_price', '150.0000')->assertJsonPath('data.0.itbis_rate', '18.00');

    $this->getJson(route('catalog.products.search', ['q' => 'zzz']))->assertOk()->assertJsonCount(0, 'data');
});

test('search requires the products view permission', function () {
    $this->actingAs(User::factory()->create());
    $this->getJson(route('catalog.products.search', ['q' => 'a']))->assertForbidden();
});
