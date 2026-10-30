<?php

use App\Actions\Sales\IssueCreditNote;
use App\Enums\InvoiceStatus;
use App\Exceptions\DocumentStateException;
use App\Exceptions\ImmutableDocumentException;
use App\Models\AccountingEvent;
use App\Models\AdditionalTax;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    activeSequence('B04', ['range_to' => 100]);
    activeSequence('B01', ['range_to' => 1000]); // issuedInvoice() only creates B01/B02 when no sequence is active
    activeSequence('B02', ['range_to' => 1000]);
});

test('a discount credit note is issued with B04, reduces the invoice balance and records the event', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice(); // 2 × 100 + 18 % = 236.00
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [['invoice_line_id' => $i->lines->first()->id, 'product_id' => $i->lines->first()->product_id, 'description' => 'Descuento', 'quantity' => '2', 'unit_price' => '10', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect()->assertSessionHas('success', 'Nota de crédito NC-000001 emitida con NCF B0400000001');
    $n = CreditNote::firstOrFail();
    expect($n->ncf)->toBe('B0400000001')->and($n->modified_ncf)->toBe($i->ncf)->and($n->total)->toBe('23.60')->and($n->ncf_expires_on)->toBeNull()->and($n->issued_at)->not->toBeNull()
        ->and($i->fresh()->credited_amount)->toBe('23.60')->and($i->fresh()->balance)->toBe('212.40')->and($i->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(AccountingEvent::where('type', 'credit_note_issued')->first()->payload['modified_ncf'])->toBe($i->ncf)
        ->and(Activity::inLog('credit_notes')->where('event', 'issued')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'credit_note_applied')->where('subject_id', $i->id)->exists())->toBeTrue()
        ->and(StockMovement::where('type', 'return')->count())->toBe(0);
    expect(fn () => $n->update(['notes' => 'x']))->toThrow(ImmutableDocumentException::class);
});

test('a credit note cannot exceed the invoice balance nor the invoiced unit price', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => '200.00'])->save();
    $i->refreshFinancials(); // balance 36.00
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [['invoice_line_id' => $i->lines->first()->id, 'product_id' => $i->lines->first()->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect()->assertSessionHas('error', 'La nota de crédito (RD$ 118.00) supera el saldo pendiente de la factura (RD$ 36.00).');
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [['invoice_line_id' => $i->lines->first()->id, 'product_id' => $i->lines->first()->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100.5', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.unit_price' => 'La nota no puede superar el precio facturado (100.0000).']);
    expect(CreditNote::count())->toBe(0)->and(NcfSequence::where('ncf_type', 'B04')->first()->next_number)->toBe(1);
});

test('a return cannot exceed the dispatched quantity and re-enters stock at the dispatch cost', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'Solo se pueden devolver unidades ya despachadas (despachadas 0.0000, devueltas 0.0000).']);

    // simulate a confirmed dispatch of 2 units at cost 50 (Task 13 does this through dispatches)
    app(InventoryService::class)->dispatch(new StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00'])->save();

    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect()->assertSessionHas('success');
    $m = StockMovement::where('type', 'return')->firstOrFail();
    expect($m->quantity)->toBe('1.0000')->and($m->unit_cost)->toBe('50.0000')->and($m->reference_type)->toBe(CreditNote::class)
        ->and(StockBalance::where('product_id', $line->product_id)->whereNull('lot_id')->first()->quantity)->toBe('999.0000')
        ->and($line->fresh()->returned_quantity)->toBe('1.0000')->and($i->fresh()->balance)->toBe('118.00')
        ->and(AccountingEvent::where('type', 'credit_note_issued')->first()->payload['returned_cost'])->toBe('50.00');
});

test('a correction releases the reservation of undispatched units', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $svc = app(InventoryService::class);
    expect($svc->available($line->product_id, $i->warehouse_id))->toBe('998.0000');
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'correction', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '3', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'Cantidad pendiente de despacho: 2.0000.']);
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'correction', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect();
    expect($svc->available($line->product_id, $i->warehouse_id))->toBe('1000.0000')->and($line->fresh()->credited_quantity)->toBe('2.0000')
        ->and($i->fresh()->balance)->toBe('0.00')->and($i->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('credit notes require an open invoice, respect scoping and render index, show, pdf and export', function () {
    actingAsRole('accounting');
    $draft = Invoice::factory()->withLines(1)->create();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($draft))->assertSessionHasErrors(['invoice_id' => 'La factura debe estar emitida.']);
    $i = issuedInvoice();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertRedirect();
    $n = CreditNote::firstOrFail();
    $this->get(route('sales.credit-notes.index'))->assertOk()->assertInertia(fn ($p) => $p->component('sales/credit-notes/index')->has('rows.data', 1));
    $this->get(route('sales.credit-notes.show', $n))->assertOk()->assertInertia(fn ($p) => $p->component('sales/credit-notes/show')->where('creditNote.ncf', 'B0400000001')->has('history'));
    $this->get(route('sales.credit-notes.create', ['invoice' => $i->id]))->assertOk()->assertInertia(fn ($p) => $p->component('sales/credit-notes/form')->where('invoice.id', $i->id)->has('invoice.lines', 1));
    $this->get(route('sales.credit-notes.pdf', $n))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('sales.credit-notes.export', ['format' => 'csv']))->assertOk();
    salesUserWithSalesperson();
    $this->get(route('sales.credit-notes.show', $n))->assertForbidden();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertForbidden();
});

test('the same invoice line cannot appear twice in one credit note', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    app(InventoryService::class)->dispatch(new StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00'])->save();

    $row = ['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => ''];
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [$row, $row]]))
        ->assertSessionHasErrors(['lines.1.invoice_line_id' => 'La línea de factura está repetida.']);
    expect($line->fresh()->returned_quantity)->toBe('0.0000');
});

test('the issue action re-checks return caps under the lock', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    app(InventoryService::class)->dispatch(new StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00', 'returned_quantity' => '2.0000'])->save();

    $payload = validCreditNotePayload($i, ['reason' => 'return', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]);
    expect(fn () => app(IssueCreditNote::class)->handle($payload, $i, $user))
        ->toThrow(DocumentStateException::class, 'supera la cantidad disponible');
    expect(CreditNote::count())->toBe(0);
});

test('credit note costs are hidden from users without the cost permission', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $line = $i->lines->first();
    app(InventoryService::class)->dispatch(new StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00'])->save();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0', 'lot_id' => '']]]))
        ->assertRedirect();
    $n = CreditNote::firstOrFail();

    // sales can view credit notes (sales.credit-notes.view) but lacks inventory.stock.view-costs
    actingAsRole('sales')->givePermissionTo('sales.invoices.view-all');
    $this->get(route('sales.credit-notes.show', $n))->assertOk()->assertInertia(fn ($p) => $p->missing('creditNote.returned_cost'));

    actingAsRole('owner');
    $this->get(route('sales.credit-notes.show', $n))->assertOk()->assertInertia(fn ($p) => $p->has('creditNote.returned_cost'));
});

test('credit and debit notes are issued with today\'s date whatever date the form sends', function () {
    actingAsRole('accounting');
    activeSequence('B03', ['range_to' => 100]);
    $i = issuedInvoice();
    $this->post(route('sales.credit-notes.store'), [...validCreditNotePayload($i), 'issue_date' => '2020-01-01'])->assertRedirect()->assertSessionHas('success');
    $this->post(route('sales.debit-notes.store'), [...validDebitNotePayload($i), 'issue_date' => '2020-01-01'])->assertRedirect()->assertSessionHas('success');
    expect(CreditNote::firstOrFail()->issue_date->toDateString())->toBe(now()->toDateString())
        ->and(DebitNote::firstOrFail()->issue_date->toDateString())->toBe(now()->toDateString());
});

test('a credit note line cannot carry less discount than the invoiced line', function () {
    actingAsRole('accounting');
    $i = issuedInvoice(['lines' => [['product_id' => Product::factory()->create(['itbis_rate' => '18.00'])->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '50']]]); // net 100 + 18 = 118.00
    $line = $i->lines->first();
    $row = fn (string $discount) => ['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => $discount, 'lot_id' => ''];
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('0')]]))
        ->assertSessionHasErrors(['lines.0.discount_percent' => 'El descuento no puede ser menor que el facturado (50.0000 %).']);
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['lines' => [$row('50')]]))->assertRedirect()->assertSessionHas('success');
    expect(CreditNote::firstOrFail()->total)->toBe('59.00')->and($i->fresh()->balance)->toBe('59.00');
});

test('discount notes skip fixed-per-unit taxes and every note uses the invoice line tax snapshot', function () {
    $user = actingAsRole('accounting');
    $adValorem = AdditionalTax::factory()->create(['code' => 'ISC-ADV', 'calculation_type' => 'percent_of_base', 'rate' => '10.0000', 'amount' => null, 'included_in_itbis_base' => true]);
    $fixed = AdditionalTax::factory()->create(['code' => 'ISC-FIX', 'calculation_type' => 'fixed_per_unit', 'rate' => null, 'amount' => '5.0000', 'included_in_itbis_base' => false]);
    $product = Product::factory()->create(['itbis_rate' => '18.00']);
    $product->additionalTaxes()->attach([$adValorem->id, $fixed->id]);
    $i = issuedInvoice(['lines' => [['product_id' => $product->id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']]]);
    $line = $i->lines->first();
    expect($i->total)->toBe('269.60'); // 200 + 20 + 10 + 18 % × 220

    $product->additionalTaxes()->detach($fixed->id); // the snapshot, not the current setup, drives the note
    $row = fn (string $qty, string $price) => ['invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'description' => '', 'quantity' => $qty, 'unit_price' => $price, 'discount_percent' => '0', 'lot_id' => ''];
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'discount', 'lines' => [$row('2', '10')]]))->assertRedirect()->assertSessionHas('success');
    $discount = CreditNote::latest('id')->firstOrFail();
    expect($discount->total)->toBe('25.96')->and($discount->additional_tax_total)->toBe('2.00')
        ->and(collect($discount->lines->first()->additional_taxes)->pluck('code')->all())->toBe(['ISC-ADV']);

    app(InventoryService::class)->dispatch(new StockLine($line->product_id, $i->warehouse_id, '2'), $i, $user);
    app(InventoryService::class)->release($line->reservations()->whereNull('released_at')->firstOrFail());
    $line->forceFill(['dispatched_quantity' => '2.0000', 'dispatched_cost' => '100.00'])->save();
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['reason' => 'return', 'lines' => [$row('1', '100')]]))->assertRedirect()->assertSessionHas('success');
    $return = CreditNote::latest('id')->firstOrFail();
    expect($return->total)->toBe('134.80')->and($return->additional_tax_total)->toBe('15.00') // 100 + 10 + 5 + 18 % × 110
        ->and(collect($return->lines->first()->additional_taxes)->pluck('code')->sort()->values()->all())->toBe(['ISC-ADV', 'ISC-FIX']);
});
