<?php

use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('initial stock import needs a warehouse and loads valid rows', function () {
    $wh = Warehouse::factory()->create();
    Product::factory()->create(['sku' => 'A-1']);
    Product::factory()->create(['sku' => 'L-1', 'tracks_lots' => true]);
    actingAsRole('warehouse');
    $file = makeSpreadsheet([['SKU', 'Cantidad', 'Costo unitario', 'Lote', 'Vencimiento'], ['a-1', '10', '100', '', ''], ['L-1', '5', '20', 'L1', '2027-01-31'], ['L-1', '5', '20', '', ''], ['NOPE', '1', '1', '', '']]);

    $this->post(route('admin.imports.preview', 'initial_stock'), ['file' => $file])->assertSessionHasErrors(['warehouse_id']);
    $this->post(route('admin.imports.preview', 'initial_stock'), ['file' => $file, 'warehouse_id' => $wh->id])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(2)
        ->and($batch->rows()->where('row_number', 4)->first()->errors['lot_code'])->toBe('El producto controla lotes: indica el lote.')
        ->and($batch->rows()->where('row_number', 5)->first()->errors)->toHaveKey('sku');

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 2 creados, 0 actualizados');
    expect(StockBalance::whereNull('lot_id')->sum('quantity'))->toBe('15.0000');
});

test('initial stock quantities and costs are bounded to the column precision', function () {
    $wh = Warehouse::factory()->create();
    Product::factory()->create(['sku' => 'B-1']);
    Product::factory()->create(['sku' => 'B-2']);
    Product::factory()->create(['sku' => 'B-3']);
    actingAsRole('warehouse');
    $file = makeSpreadsheet([['SKU', 'Cantidad', 'Costo unitario', 'Lote', 'Vencimiento'], ['B-1', '99999999999', '1', '', ''], ['B-2', '1', '1.12345', '', ''], ['B-3', '1.5', '350.5', '', '']]);
    $this->post(route('admin.imports.preview', 'initial_stock'), ['file' => $file, 'warehouse_id' => $wh->id])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(1)->and($batch->invalid_rows)->toBe(2)
        ->and($batch->rows()->where('row_number', 2)->first()->errors)->toHaveKey('quantity')
        ->and($batch->rows()->where('row_number', 3)->first()->errors)->toHaveKey('unit_cost');
});
