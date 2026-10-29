<?php

use App\Models\ImportBatch;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('missing price list meta is rejected and prices are imported into the chosen list', function () {
    $list = PriceList::factory()->create();
    $p1 = Product::factory()->create(['sku' => 'A-1']);
    PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_id' => $p1->id, 'price' => '1.0000']);
    Product::factory()->create(['sku' => 'A-2']);
    actingAsRole('accounting');
    $file = makeSpreadsheet([['SKU', 'Precio'], ['a-1', '150.5'], ['A-2', '99'], ['NOPE', '1'], ['A-2', '-5']]);

    $this->post(route('admin.imports.preview', 'price_list_items'), ['file' => $file])->assertSessionHasErrors(['price_list_id']);
    $this->post(route('admin.imports.preview', 'price_list_items'), ['file' => $file, 'price_list_id' => (string) $list->id])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(2)->and($batch->meta)->toBe(['price_list_id' => (string) $list->id]);

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 1 creados, 1 actualizados');
    expect(PriceListItem::where('product_id', $p1->id)->first()->price)->toBe('150.5000')->and(PriceListItem::where('price_list_id', $list->id)->count())->toBe(2);
});
