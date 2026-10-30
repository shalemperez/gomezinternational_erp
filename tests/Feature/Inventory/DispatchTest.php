<?php

use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\DispatchStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ImmutableDocumentException;
use App\Models\AccountingEvent;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\Lot;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('warehouse creates a draft conduce from an issued invoice and confirms a partial dispatch', function () {
    $user = actingAsRole('warehouse');
    $i = issuedInvoice(); // 2 units reserved, stock 1000 @ 50
    $line = $i->lines->first();
    $this->get(route('inventory.dispatches.create', ['invoice' => $i->id]))->assertOk()->assertInertia(fn ($p) => $p->component('inventory/dispatches/form')->where('invoice.number', $i->number)->where('invoice.lines.0.remaining', '2.0000'));
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]))->assertRedirect()->assertSessionHas('success', 'Conduce guardado como borrador');
    $d = Dispatch::firstOrFail();
    expect($d->status)->toBe(DispatchStatus::Draft)->and($d->number)->toBeNull()->and($d->warehouse_id)->toBe($i->warehouse_id)->and($d->lines()->count())->toBe(1);

    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect(route('inventory.dispatches.show', $d))->assertSessionHas('success', 'Conduce CND-000001 confirmado');
    $d->refresh();
    $line->refresh();
    expect($d->status)->toBe(DispatchStatus::Confirmed)->and($d->number)->toBe('CND-000001')->and($d->confirmed_by)->toBe($user->id)->and($d->total_cost)->toBe('50.00')
        ->and($line->dispatched_quantity)->toBe('1.0000')->and($line->dispatched_cost)->toBe('50.00')->and($i->fresh()->dispatch_status)->toBe('partial')
        ->and(StockMovement::where('type', 'dispatch')->where('reference_id', $d->id)->first()->quantity)->toBe('1.0000')
        ->and(StockReservation::whereNull('released_at')->first()->quantity)->toBe('1.0000')
        ->and(app(InventoryService::class)->available($line->product_id, $i->warehouse_id))->toBe('998.0000')
        ->and(AccountingEvent::where('type', 'dispatch_confirmed')->first()->payload['total_cost'])->toBe('50.00')
        ->and(Activity::inLog('dispatches')->where('event', 'confirmed')->where('subject_id', $d->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'dispatched')->where('subject_id', $i->id)->exists())->toBeTrue();
    expect(fn () => $d->update(['notes' => 'x']))->toThrow(ImmutableDocumentException::class);
    $this->post(route('inventory.dispatches.confirm', $d))->assertSessionHas('error', 'El conduce está Confirmado y no admite esta acción');

    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]))->assertRedirect();
    $this->post(route('inventory.dispatches.confirm', Dispatch::latest('id')->first()))->assertRedirect();
    expect($i->fresh()->dispatch_status)->toBe('complete')->and(StockReservation::whereNull('released_at')->count())->toBe(0);
});

test('a dispatch cannot exceed the invoiced quantity', function () {
    actingAsRole('warehouse');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '3', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'Cantidad pendiente de despacho: 2.0000.']);
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines' => 'Indica al menos una cantidad a despachar.']);
    $this->post(route('inventory.dispatches.store'), validDispatchPayload(Invoice::factory()->withLines(1)->create()))->assertSessionHasErrors(['invoice_id' => 'Solo se despachan facturas emitidas']);
});

test('lot-tracked products get a fefo suggestion and confirm is refused when the lot has no stock', function () {
    $user = actingAsRole('warehouse');
    activeSequence('B01', ['range_to' => 100]);
    $product = Product::factory()->create(['tracks_lots' => true, 'itbis_rate' => '18.00']);
    $wh = Warehouse::factory()->create(['is_default' => true]);
    $svc = app(InventoryService::class);
    $svc->receive(new StockLine($product->id, $wh->id, '5', '10', null, 'LATE', '2027-06-30'), StockMovementType::Initial, $user, $user);
    $svc->receive(new StockLine($product->id, $wh->id, '1', '10', null, 'SOON', '2026-12-01'), StockMovementType::Initial, $user, $user);
    $draft = app(SaveInvoice::class)->handle(validInvoicePayload(['warehouse_id' => $wh->id, 'salesperson_id' => Salesperson::factory()->create()->id, 'lines' => [['product_id' => $product->id, 'quantity' => '3', 'unit_price' => '100', 'discount_percent' => '0', 'description' => '']]]), $user->givePermissionTo('sales.invoices.view-all'));
    $i = app(IssueInvoice::class)->handle($draft, $user);
    $line = $i->lines->first();
    $soon = Lot::where('code', 'SOON')->firstOrFail();
    $late = Lot::where('code', 'LATE')->firstOrFail();

    $this->get(route('inventory.dispatches.create', ['invoice' => $i->id]))->assertInertia(fn ($p) => $p->where('invoice.lines.0.suggested_lots.0.code', 'SOON')->where('invoice.lines.0.suggested_lots.0.quantity', '1.0000')->where('invoice.lines.0.suggested_lots.1.code', 'LATE')->where('invoice.lines.0.suggested_lots.1.quantity', '2.0000'));
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '3', 'lot_id' => '']]]))->assertSessionHasErrors(['lines.0.lot_id' => 'El producto controla lotes: indica el lote.']);
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '2', 'lot_id' => $soon->id]]]))->assertRedirect();
    $d = Dispatch::firstOrFail();
    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect()->assertSessionHas('error', fn (string $m) => str_starts_with($m, 'Existencia insuficiente de '.$product->sku));
    expect($d->fresh()->status)->toBe(DispatchStatus::Draft)->and(StockMovement::where('type', 'dispatch')->count())->toBe(0);

    $this->put(route('inventory.dispatches.update', $d), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => $soon->id], ['invoice_line_id' => $line->id, 'quantity' => '2', 'lot_id' => $late->id]]]))->assertRedirect();
    $this->post(route('inventory.dispatches.confirm', $d))->assertRedirect()->assertSessionHas('success');
    expect(StockMovement::where('type', 'dispatch')->count())->toBe(2)->and($line->fresh()->dispatched_quantity)->toBe('3.0000')->and($i->fresh()->dispatch_status)->toBe('complete');
});

test('permissions, drafts, pdf, index and export', function () {
    actingAsRole('sales');
    $i = issuedInvoice();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i))->assertForbidden();
    actingAsRole('warehouse');
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i))->assertRedirect();
    $d = Dispatch::firstOrFail();
    $this->get(route('inventory.dispatches.index'))->assertInertia(fn ($p) => $p->component('inventory/dispatches/index')->has('rows.data', 1));
    $this->get(route('inventory.dispatches.show', $d))->assertInertia(fn ($p) => $p->component('inventory/dispatches/show')->where('dispatch.status', 'draft')->has('history'));
    $this->get(route('inventory.dispatches.pdf', $d))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->delete(route('inventory.dispatches.destroy', $d))->assertRedirect(route('inventory.dispatches.index'))->assertSessionHas('success', 'Borrador de conduce eliminado');
    expect(Dispatch::count())->toBe(0);
    $this->get(route('inventory.dispatches.export', ['format' => 'csv']))->assertForbidden(); // warehouse never exports (P1 rule)
    actingAsRole('owner');
    $this->get(route('inventory.dispatches.export', ['format' => 'csv']))->assertOk();
});

test('dispatch history hides costs from users without the cost permission', function () {
    actingAsRole('warehouse');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]));
    $d = Dispatch::firstOrFail();
    $this->post(route('inventory.dispatches.confirm', $d));

    $response = $this->get(route('inventory.dispatches.show', $d))->assertOk();
    $history = $response->viewData('page')['props']['history'];
    expect(json_encode($history))->not->toContain('total_cost')->not->toContain('unit_cost');
});

test('dispatch history exposes costs to owner', function () {
    actingAsRole('owner');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]));
    $d = Dispatch::firstOrFail();
    $this->post(route('inventory.dispatches.confirm', $d));

    $response = $this->get(route('inventory.dispatches.show', $d))->assertOk();
    $history = $response->viewData('page')['props']['history'];
    expect(json_encode($history))->toContain('total_cost');
});
