<?php

use App\Enums\StockMovementType;
use App\Jobs\CheckStockAlerts;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\StockAlert;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    $this->wh = Warehouse::factory()->create();
});

test('stock index shows balances, hides costs without permission and filters low stock', function () {
    $user = actingAsRole('warehouse');
    $svc = app(InventoryService::class);
    $low = Product::factory()->create(['sku' => 'LOW-1', 'minimum_stock' => '10']);
    $ok = Product::factory()->create(['sku' => 'OK-1', 'minimum_stock' => '1']);
    $svc->receive(new StockLine($low->id, $this->wh->id, '4', '25'), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($ok->id, $this->wh->id, '8', '10'), StockMovementType::Initial, $user, $user);

    $this->get(route('inventory.stock.index'))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/stock/index')->has('rows.data', 2)->where('canViewCosts', false)->missing('rows.data.0.average_cost')->missing('rows.data.0.stock_value'));
    $this->get(route('inventory.stock.index', ['low_only' => '1']))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.sku', 'LOW-1')->where('rows.data.0.is_low', true)->where('rows.data.0.available', '4.0000'));
    $this->get(route('inventory.stock.export', ['format' => 'csv']))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('inventory.stock.index', ['search' => 'low']))->assertInertia(fn ($p) => $p->where('canViewCosts', true)->where('rows.data.0.average_cost', '25.0000')->where('rows.data.0.stock_value', '100.00'));
    $this->get(route('inventory.stock.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->latest('id')->first()->properties['table'])->toBe('stock');
    actingAsRole('sales');
    $this->get(route('inventory.stock.index'))->assertOk();
    $this->get(route('inventory.movements.index'))->assertForbidden();
});

test('kardex lists signed movements with references and filters by product and type', function () {
    $user = actingAsRole('warehouse');
    $svc = app(InventoryService::class);
    $p = Product::factory()->create(['sku' => 'K-1']);
    $svc->receive(new StockLine($p->id, $this->wh->id, '10', '5'), StockMovementType::Initial, $user, $user);
    $svc->dispatch(new StockLine($p->id, $this->wh->id, '3'), $user, $user);
    $this->get(route('inventory.movements.index', ['product_id' => $p->id]))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/movements/index')->has('rows.data', 2)->where('rows.data.0.signed_quantity', '-3.0000')->where('rows.data.0.balance_after', '7.0000')->where('rows.data.1.signed_quantity', '10.0000')->missing('rows.data.0.unit_cost'));
    $this->get(route('inventory.movements.index', ['type' => 'dispatch']))->assertInertia(fn ($p) => $p->has('rows.data', 1));
    $this->get(route('inventory.movements.export'))->assertForbidden();
    actingAsRole('owner');
    $this->get(route('inventory.movements.index'))->assertInertia(fn ($p) => $p->where('rows.data.0.unit_cost', '5.0000'));
    $this->get(route('inventory.movements.export', ['format' => 'xlsx']))->assertOk();
});

test('lots page flags expired and expiring lots using the configured days', function () {
    $user = actingAsRole('warehouse');
    app(Settings::class)->set('inventory.expiring_days', 45);
    $svc = app(InventoryService::class);
    $p = Product::factory()->create(['tracks_lots' => true]);
    $svc->receive(new StockLine($p->id, $this->wh->id, '2', '1', null, 'OLD', now()->subDay()->toDateString()), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($p->id, $this->wh->id, '2', '1', null, 'SOON', now()->addDays(40)->toDateString()), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($p->id, $this->wh->id, '2', '1', null, 'FAR', now()->addDays(200)->toDateString()), StockMovementType::Initial, $user, $user);
    $this->get(route('inventory.lots.index'))->assertOk()->assertInertia(fn ($pg) => $pg->component('inventory/lots/index')->has('rows.data', 3)->where('expiringDays', 45)->where('rows.data.0.lot_code', 'OLD')->where('rows.data.0.state', 'expired')->where('rows.data.1.state', 'expiring')->where('rows.data.2.state', 'ok'));
    $this->get(route('inventory.lots.index', ['expiring_only' => '1']))->assertInertia(fn ($pg) => $pg->has('rows.data', 2));
});

test('the alert job notifies users with the alerts permission and logs', function () {
    Notification::fake();
    $user = actingAsRole('warehouse');
    $sales = User::factory()->create();
    $sales->assignRole('sales');
    $svc = app(InventoryService::class);
    $low = Product::factory()->create(['minimum_stock' => '10']);
    $svc->receive(new StockLine($low->id, $this->wh->id, '1', '1'), StockMovementType::Initial, $user, $user);
    (new CheckStockAlerts)->handle($svc, app(Settings::class));
    Notification::assertSentTo($user, StockAlert::class, fn (StockAlert $n) => count($n->lowStock) === 1 && count($n->expiringLots) === 0);
    Notification::assertNotSentTo($sales, StockAlert::class);
    expect(Activity::inLog('inventory')->where('event', 'alerts_checked')->first()->properties['low_stock'])->toBe(1);

    Notification::fake();
    $svc->receive(new StockLine($low->id, $this->wh->id, '20', '1'), StockMovementType::Receipt, $user, $user);
    (new CheckStockAlerts)->handle($svc, app(Settings::class));
    Notification::assertNothingSent();
});
