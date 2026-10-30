<?php

use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\StockMovementType;
use App\Exceptions\DocumentStateException;
use App\Exceptions\ImmutableDocumentException;
use App\Models\AccountingEvent;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Dispatch;
use App\Models\DocumentSequence;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\Salesperson;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    $this->sp = Salesperson::factory()->create();
});

function draftFor(array $over, User $actor): Invoice
{
    $payload = validInvoicePayload($over);
    foreach ($payload['lines'] as $line) {
        app(InventoryService::class)->receive(new StockLine($line['product_id'], $payload['warehouse_id'], '100', '50'), StockMovementType::Initial, $actor, $actor);
    }

    return app(SaveInvoice::class)->handle($payload, $actor);
}

test('issuing assigns number and ncf, snapshots the client, reserves stock and records the accounting event', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_to' => 10]);
    $client = Client::factory()->create(['business_name' => 'ACME SRL', 'tax_id' => '101850043', 'credit_limit' => '0']);
    $draft = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $user);

    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect(route('sales.invoices.show', $draft))->assertSessionHas('success', 'Factura FAC-000001 emitida con NCF B0100000001');
    $i = $draft->fresh();
    expect($i->status)->toBe(InvoiceStatus::Issued)->and($i->number)->toBe('FAC-000001')->and($i->ncf)->toBe('B0100000001')->and($i->ncf_type->value)->toBe('B01')
        ->and($i->ncf_expires_on)->not->toBeNull()->and($i->fiscal_mode)->toBe('ncf')->and($i->issued_at)->not->toBeNull()
        ->and($i->client_name)->toBe('ACME SRL')->and($i->client_tax_id)->toBe('101850043')->and($i->balance)->toBe('236.00')->and($i->paid_amount)->toBe('0.00')
        ->and(StockReservation::where('reservable_type', InvoiceLine::class)->count())->toBe(1)
        ->and(app(InventoryService::class)->available($i->lines->first()->product_id, $i->warehouse_id))->toBe('98.0000')
        ->and(Activity::inLog('invoices')->where('event', 'issued')->where('subject_id', $i->id)->where('causer_id', $user->id)->exists())->toBeTrue();
    $event = AccountingEvent::where('type', 'invoice_issued')->firstOrFail();
    expect($event->document_id)->toBe($i->id)->and($event->payload['ncf'])->toBe('B0100000001')->and($event->payload['total'])->toBe('236.00')->and($event->payload['itbis_total'])->toBe('36.00')->and($event->payload['currency'])->toBe('DOP');

    expect(fn () => $i->update(['notes' => 'x']))->toThrow(ImmutableDocumentException::class);
    $this->post(route('sales.invoices.issue', $i), [])->assertRedirect()->assertSessionHas('error', 'La factura está Emitida y no admite esta acción');
});

test('consumo clients get B02 and the threshold requires the client tax id', function () {
    $user = actingAsRole('accounting');
    activeSequence('B02');
    $consumer = Client::factory()->create(['taxpayer_type' => 'consumidor_final', 'tax_id_type' => 'none', 'tax_id' => null, 'credit_limit' => '0']);
    $small = draftFor(['client_id' => $consumer->id, 'salesperson_id' => $this->sp->id], $user);
    expect(app(IssueInvoice::class)->handle($small, $user)->ncf)->toBe('B0200000001');

    $big = draftFor(['client_id' => $consumer->id, 'salesperson_id' => $this->sp->id, 'lines' => [['product_id' => Product::factory()->create(['itbis_rate' => '0.00'])->id, 'quantity' => '1', 'unit_price' => '250000', 'discount_percent' => '0', 'description' => '']]], $user);
    expect(fn () => app(IssueInvoice::class)->handle($big, $user))->toThrow(DocumentStateException::class, 'Las facturas de consumo desde RD$ 250,000.00 requieren el RNC o cédula del comprador (Norma General 10-2018).');
    expect($big->fresh()->status)->toBe(InvoiceStatus::Draft)->and(NcfSequence::where('ncf_type', 'B02')->first()->next_number)->toBe(2);
    app(Settings::class)->set('fiscal.consumo_id_threshold', '300000.00');
    expect(app(IssueInvoice::class)->handle($big->fresh(), $user)->ncf)->toBe('B0200000002');
});

test('credit limit blocks credit invoices unless an authorised user overrides with a reason', function () {
    $sales = salesUserWithSalesperson()['user'];
    $sales->givePermissionTo('sales.invoices.view-all');
    activeSequence('B01', ['range_to' => 10]);
    $client = Client::factory()->create(['credit_limit' => '300.00']);
    $first = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $sales);
    app(IssueInvoice::class)->handle($first, $sales); // outstanding 236.00
    $second = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $sales);

    $this->post(route('sales.invoices.issue', $second), [])->assertRedirect()->assertSessionHas('error', 'El cliente supera su límite de crédito (disponible RD$ 64.00). Un usuario con permiso puede autorizar el exceso.');
    $this->post(route('sales.invoices.issue', $second), ['credit_override' => '1', 'credit_override_reason' => 'Autorizado por gerencia'])->assertForbidden();

    $owner = actingAsRole('owner');
    $this->post(route('sales.invoices.issue', $second), ['credit_override' => '1'])->assertSessionHasErrors(['credit_override_reason']);
    $this->post(route('sales.invoices.issue', $second), ['credit_override' => '1', 'credit_override_reason' => 'Autorizado por gerencia'])->assertRedirect();
    expect($second->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(Activity::inLog('invoices')->where('event', 'credit_limit_overridden')->where('subject_id', $second->id)->where('causer_id', $owner->id)->first()->properties['reason'])->toBe('Autorizado por gerencia');

    $cash = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id, 'payment_terms' => 'cash', 'credit_days' => 0], $owner);
    expect(app(IssueInvoice::class)->handle($cash, $owner)->status)->toBe(InvoiceStatus::Issued); // cash sales ignore the limit
});

test('issue is refused and nothing is written when the ncf sequence is exhausted or stock is insufficient', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1, 'next_number' => 2, 'exhausted_at' => now()]);
    $draft = draftFor(['salesperson_id' => $this->sp->id], $user);
    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()->assertSessionHas('error', 'La secuencia de B01 está agotada; registra una nueva secuencia autorizada por la DGII.');
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Draft)->and($draft->fresh()->number)->toBeNull()->and(StockReservation::count())->toBe(0)->and(AccountingEvent::where('type', 'invoice_issued')->count())->toBe(0);

    activeSequence('B01', ['range_from' => 10, 'range_to' => 20]);
    $big = app(SaveInvoice::class)->handle(validInvoicePayload(['salesperson_id' => $this->sp->id, 'lines' => [['product_id' => Product::factory()->create(['sku' => 'NOSTOCK'])->id, 'quantity' => '3', 'unit_price' => '1', 'discount_percent' => '0', 'description' => '']]]), $user);
    $this->post(route('sales.invoices.issue', $big), [])->assertSessionHas('error', fn (string $m) => str_starts_with($m, 'Disponible insuficiente de NOSTOCK'));
    expect($big->fresh()->number)->toBeNull()->and(DocumentSequence::where('kind', 'invoice')->first()->next_number)->toBe(1);
});

test('sales users with issue permission can issue their own invoices but not cancel', function () {
    $sales = salesUserWithSalesperson()['user'];
    activeSequence('B01', ['range_to' => 10]);
    $own = draftFor(['salesperson_id' => $this->sp->id, 'client_id' => Client::factory()->create(['salesperson_id' => $sales->salesperson->id, 'credit_limit' => '0'])->id], $sales);
    $this->post(route('sales.invoices.issue', $own), [])->assertRedirect(route('sales.invoices.show', $own));
    $this->post(route('sales.invoices.cancel', $own), ['reason' => 'Error'])->assertForbidden();
});

test('cancel releases reservations, records the event and is refused once paid or dispatched', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.invoices.cancel', $i), ['reason' => ''])->assertSessionHasErrors(['reason']);
    $this->post(route('sales.invoices.cancel', $i), ['reason' => 'Cliente desistió'])->assertRedirect()->assertSessionHas('success', "Factura {$i->number} anulada");
    $i->refresh();
    expect($i->status)->toBe(InvoiceStatus::Cancelled)->and($i->cancel_reason)->toBe('Cliente desistió')->and($i->cancelled_by)->toBe($user->id)
        ->and(StockReservation::whereNull('released_at')->count())->toBe(0)
        ->and(app(InventoryService::class)->available($i->lines->first()->product_id, $i->warehouse_id))->toBe('1000.0000')
        ->and(AccountingEvent::where('type', 'invoice_cancelled')->where('document_id', $i->id)->first()->payload['ncf'])->toBe($i->ncf)
        ->and(Activity::inLog('invoices')->where('event', 'cancelled')->where('subject_id', $i->id)->exists())->toBeTrue();

    $paid = issuedInvoice();
    $paid->forceFill(['paid_amount' => '10.00'])->save();
    $paid->refreshFinancials();
    $this->post(route('sales.invoices.cancel', $paid), ['reason' => 'x'])->assertSessionHas('error', 'Solo se pueden anular facturas sin cobros, notas ni despachos; usa una nota de crédito.');
});

test('index filters, export and the financial status helper', function () {
    actingAsRole('owner');
    $a = issuedInvoice();
    $a->forceFill(['paid_amount' => '236.00'])->save();
    $a->refreshFinancials();
    expect($a->fresh()->status)->toBe(InvoiceStatus::Paid)->and($a->fresh()->balance)->toBe('0.00');
    $b = issuedInvoice();
    $b->forceFill(['paid_amount' => '100.00'])->save();
    $b->refreshFinancials();
    expect($b->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)->and($b->fresh()->balance)->toBe('136.00');
    $this->get(route('sales.invoices.index', ['status' => 'partially_paid']))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.balance', '136.00'));
    $this->get(route('sales.invoices.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->latest('id')->first()->properties['table'])->toBe('invoices');
});

test('a credit override sent as true still requires a reason', function () {
    $owner = actingAsRole('owner');
    activeSequence('B01', ['range_to' => 10]);
    $client = Client::factory()->create(['credit_limit' => '300.00']);
    $draft = draftFor(['client_id' => $client->id, 'salesperson_id' => $this->sp->id], $owner);

    $this->post(route('sales.invoices.issue', $draft), ['credit_override' => 'true'])->assertSessionHasErrors(['credit_override_reason' => 'Indica el motivo de la autorización.']);
});

test('cancel is refused while a confirmed dispatch exists', function () {
    actingAsRole('owner');
    $i = issuedInvoice();
    $line = $i->lines->first();
    $this->post(route('inventory.dispatches.store'), validDispatchPayload($i, ['lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'lot_id' => '']]]))->assertRedirect();
    $this->post(route('inventory.dispatches.confirm', Dispatch::firstOrFail()))->assertRedirect()->assertSessionHas('success');

    $this->post(route('sales.invoices.cancel', $i), ['reason' => 'Cliente desistió'])->assertSessionHas('error', 'Solo se pueden anular facturas sin cobros, notas ni despachos; usa una nota de crédito.');
    expect($i->fresh()->status)->not->toBe(InvoiceStatus::Cancelled);
});

test('issuing stamps today as the fiscal date and judges the sequence expiry on that date', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_from' => 1, 'range_to' => 10, 'expires_on' => now()->subDay()->toDateString()]);
    $draft = draftFor(['salesperson_id' => $this->sp->id, 'issue_date' => now()->subMonths(2)->toDateString()], $user);
    expect($draft->issue_date->toDateString())->toBe(now()->subMonths(2)->toDateString());

    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()->assertSessionHas('error', 'La secuencia de B01 venció el '.now()->subDay()->format('d/m/Y').'; registra una nueva secuencia autorizada por la DGII.');
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Draft);

    activeSequence('B01', ['range_from' => 100, 'range_to' => 200]);
    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()->assertSessionHas('success');
    $i = $draft->fresh();
    expect($i->issue_date->toDateString())->toBe(now()->toDateString())->and($i->due_date->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and(AccountingEvent::where('type', 'invoice_issued')->first()->payload['issue_date'])->toBe(now()->toDateString());
});

test('paid invoices keep the dispatch action until fully dispatched', function () {
    actingAsRole('owner');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => $i->total])->save();
    $i->refreshFinancials();
    expect($i->fresh()->status)->toBe(InvoiceStatus::Paid);
    $this->get(route('sales.invoices.show', $i))->assertOk()->assertInertia(fn ($p) => $p->where('can.dispatch', true)->where('can.receipt', false));
    $i->forceFill(['dispatch_status' => 'complete'])->save();
    $this->get(route('sales.invoices.show', $i))->assertOk()->assertInertia(fn ($p) => $p->where('can.dispatch', false));
});

test('a foreign-currency draft is issued at the exchange rate of the issue date', function () {
    $user = actingAsRole('accounting');
    activeSequence('B01', ['range_to' => 10]);
    $usd = Currency::query()->where('code', 'USD')->firstOrFail();
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->subDays(10)->toDateString(), 'rate' => '58.000000']);
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->toDateString(), 'rate' => '60.000000']);
    $draft = draftFor(['currency_id' => $usd->id, 'issue_date' => now()->subDays(10)->toDateString(), 'salesperson_id' => $this->sp->id], $user);
    expect($draft->fresh()->exchange_rate)->toBe('58.000000')->and($draft->fresh()->total_base)->toBe('13688.00');

    app(IssueInvoice::class)->handle($draft, $user);
    $i = $draft->fresh();
    expect($i->exchange_rate)->toBe('60.000000')->and($i->total)->toBe('236.00')->and($i->total_base)->toBe('14160.00');
});
