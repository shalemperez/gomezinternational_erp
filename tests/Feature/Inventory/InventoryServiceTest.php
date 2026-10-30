<?php

use App\Enums\StockMovementType;
use App\Exceptions\ImmutableDocumentException;
use App\Exceptions\InventoryException;
use App\Models\AccountingEvent;
use App\Models\Lot;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;

beforeEach(function () {
    (new CurrencySeeder)->run();
    $this->actor = User::factory()->create();
    $this->wh = Warehouse::factory()->create();
    $this->svc = app(InventoryService::class);
});

test('receiving stock creates balances, updates weighted average cost and logs movement and event', function () {
    $p = Product::factory()->create(['sku' => 'A-1']);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '100'), StockMovementType::Initial, $this->actor, $this->actor);
    $m = $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '120'), StockMovementType::Receipt, $this->actor, $this->actor);

    $b = StockBalance::where('product_id', $p->id)->where('warehouse_id', $this->wh->id)->firstOrFail();
    expect($b->quantity)->toBe('20.0000')->and($b->average_cost)->toBe('110.0000')->and($b->available)->toBe('20.0000')
        ->and($m->balance_after)->toBe('20.0000')->and($m->total_cost)->toBe('1200.00')
        ->and(StockMovement::count())->toBe(2)
        ->and(AccountingEvent::where('type', 'inventory_movement')->count())->toBe(2)
        ->and(AccountingEvent::latest('id')->first()->payload['total_cost'])->toBe('1200.00');
});

test('lot-tracked products require lot data and keep per-lot balances', function () {
    $p = Product::factory()->create(['sku' => 'L-1', 'tracks_lots' => true]);
    expect(fn () => $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10'), StockMovementType::Initial, $this->actor, $this->actor))
        ->toThrow(InventoryException::class, 'El producto L-1 controla lotes: indica lote y fecha de vencimiento');

    $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10', null, 'LOTE-A', '2027-01-31'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '7', '10', null, 'LOTE-B', '2026-12-01'), StockMovementType::Initial, $this->actor, $this->actor);

    expect(Lot::where('product_id', $p->id)->count())->toBe(2)
        ->and(StockBalance::where('product_id', $p->id)->whereNotNull('lot_id')->count())->toBe(2)
        ->and($this->svc->available($p->id, $this->wh->id))->toBe('12.0000');
});

test('fefo suggests the lots that expire first', function () {
    $p = Product::factory()->create(['tracks_lots' => true]);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '5', '10', null, 'LATE', '2027-06-30'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '3', '10', null, 'SOON', '2026-12-01'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '4', '10', null, 'NOEXP', null), StockMovementType::Initial, $this->actor, $this->actor);

    $s = $this->svc->suggestLots($p->id, $this->wh->id, '6');
    expect($s)->toHaveCount(2)->and($s[0]['code'])->toBe('SOON')->and($s[0]['quantity'])->toBe('3.0000')->and($s[1]['code'])->toBe('LATE')->and($s[1]['quantity'])->toBe('3.0000');
});

test('reservations reduce availability and dispatch refuses when stock is insufficient', function () {
    $p = Product::factory()->create(['sku' => 'R-1']);
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '50'), StockMovementType::Initial, $this->actor, $this->actor);
    $res = $this->svc->reserve($this->actor, $p->id, $this->wh->id, '8');
    expect($this->svc->available($p->id, $this->wh->id))->toBe('2.0000');
    expect(fn () => $this->svc->reserve($this->actor, $p->id, $this->wh->id, '3'))->toThrow(InventoryException::class, "Disponible insuficiente de R-1 en {$this->wh->name}: disponible 2.0000, solicitado 3.0000");

    $this->svc->release($res, '3');
    expect($this->svc->available($p->id, $this->wh->id))->toBe('5.0000')->and($res->fresh()->released_at)->toBeNull();
    $this->svc->release($res);
    expect($this->svc->available($p->id, $this->wh->id))->toBe('10.0000')->and($res->fresh()->released_at)->not->toBeNull();

    expect(fn () => $this->svc->dispatch(new StockLine($p->id, $this->wh->id, '11'), $this->actor, $this->actor))
        ->toThrow(InventoryException::class, "Existencia insuficiente de R-1 en {$this->wh->name}: disponible 10.0000");
    $m = $this->svc->dispatch(new StockLine($p->id, $this->wh->id, '4'), $this->actor, $this->actor);
    expect($m->unit_cost)->toBe('50.0000')->and($m->total_cost)->toBe('200.00')->and($m->balance_after)->toBe('6.0000')->and($m->type)->toBe(StockMovementType::Dispatch);
});

test('returns re-enter stock at the given cost without changing the average', function () {
    $p = Product::factory()->create();
    $this->svc->receive(new StockLine($p->id, $this->wh->id, '10', '100'), StockMovementType::Initial, $this->actor, $this->actor);
    $this->svc->dispatch(new StockLine($p->id, $this->wh->id, '4'), $this->actor, $this->actor);
    $m = $this->svc->returnStock(new StockLine($p->id, $this->wh->id, '1', '100'), $this->actor, $this->actor);
    $b = StockBalance::where('product_id', $p->id)->firstOrFail();
    expect($b->quantity)->toBe('7.0000')->and($b->average_cost)->toBe('100.0000')->and($m->type)->toBe(StockMovementType::Return);
});

test('movements are append-only and low stock and expiring lots are reported', function () {
    $p = Product::factory()->create(['minimum_stock' => '5']);
    $m = $this->svc->receive(new StockLine($p->id, $this->wh->id, '2', '1'), StockMovementType::Initial, $this->actor, $this->actor);
    expect(fn () => $m->update(['quantity' => '99']))->toThrow(ImmutableDocumentException::class);
    expect($this->svc->lowStock()->pluck('id')->all())->toBe([$p->id]);

    $l = Product::factory()->create(['tracks_lots' => true]);
    $this->svc->receive(new StockLine($l->id, $this->wh->id, '1', '1', null, 'X', now()->addDays(10)->toDateString()), StockMovementType::Initial, $this->actor, $this->actor);
    expect($this->svc->expiringLots(30))->toHaveCount(1)->and($this->svc->expiringLots(5))->toHaveCount(0);
});
