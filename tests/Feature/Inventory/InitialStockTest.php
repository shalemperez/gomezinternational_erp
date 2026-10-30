<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new CurrencySeeder)->run());

test('manual initial stock requires permission and loads lines with lots and cost', function () {
    $wh = Warehouse::factory()->create();
    $plain = Product::factory()->create();
    $lotted = Product::factory()->create(['tracks_lots' => true]);
    $payload = ['note' => 'Carga inicial', 'lines' => [
        ['product_id' => $plain->id, 'warehouse_id' => $wh->id, 'quantity' => '10', 'unit_cost' => '100', 'lot_code' => '', 'lot_expires_on' => ''],
        ['product_id' => $lotted->id, 'warehouse_id' => $wh->id, 'quantity' => '5', 'unit_cost' => '20', 'lot_code' => 'L1', 'lot_expires_on' => '2027-01-31'],
    ]];

    actingAsRole('sales');
    $this->post(route('inventory.initial-stock.store'), $payload)->assertForbidden();

    actingAsRole('warehouse');
    $this->get(route('inventory.initial-stock.create'))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/initial-stock/form'));
    $this->post(route('inventory.initial-stock.store'), $payload)->assertRedirect(route('inventory.stock.index'))->assertSessionHas('success', 'Existencias iniciales cargadas: 2 líneas');

    expect(StockMovement::where('type', 'initial')->count())->toBe(2)
        ->and(StockBalance::where('product_id', $plain->id)->first()->quantity)->toBe('10.0000')
        ->and(StockBalance::where('product_id', $lotted->id)->whereNotNull('lot_id')->first()->quantity)->toBe('5.0000')
        ->and(Activity::inLog('initial_stock_loads')->where('event', 'created')->exists())->toBeTrue();
});

test('validation: lot data required for lot-tracked products and positive quantities', function () {
    $wh = Warehouse::factory()->create();
    $lotted = Product::factory()->create(['tracks_lots' => true]);
    actingAsRole('warehouse');
    $this->post(route('inventory.initial-stock.store'), ['note' => '', 'lines' => [['product_id' => $lotted->id, 'warehouse_id' => $wh->id, 'quantity' => '0', 'unit_cost' => '-1', 'lot_code' => '', 'lot_expires_on' => '']]])
        ->assertSessionHasErrors(['note', 'lines.0.quantity', 'lines.0.unit_cost', 'lines.0.lot_code' => 'El producto controla lotes: indica el lote.']);
});
