<?php

use App\Actions\Sales\CancelInvoice;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Salesperson;
use App\Models\SentEmail;
use App\Services\Sales\ClientStatementService;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    (new EmailTemplateSeeder)->run();
});

function openInvoiceDue(Client $client, string $dueDate): Invoice
{
    $i = issuedInvoice(['client_id' => $client->id]);
    Invoice::query()->whereKey($i->id)->update(['due_date' => $dueDate]); // query builder: bypasses the immutability guard on purpose (test fixture)

    return $i->fresh();
}

test('aging buckets open balances by days overdue and sums in base currency', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00'));
    actingAsRole('accounting');
    $client = Client::factory()->create(['business_name' => 'Aged SRL']);
    $asOf = CarbonImmutable::parse('2026-11-30');
    openInvoiceDue($client, '2026-12-05');            // current
    openInvoiceDue($client, '2026-11-15');            // 15 days
    $partly = openInvoiceDue($client, '2026-10-15');  // 46 days, balance 200 after a 36.00 receipt
    $this->post(route('sales.receipts.store'), validReceiptPayload($partly, ['amount_received' => '36', 'applications' => [['invoice_id' => $partly->id, 'amount' => '36']]]))->assertRedirect();
    openInvoiceDue($client, '2026-09-10');            // 81 days
    openInvoiceDue($client, '2026-07-01');            // 152 days
    $cancelled = openInvoiceDue($client, '2026-07-01');
    app(CancelInvoice::class)->handle($cancelled, auth()->user(), 'x');

    $aging = app(ClientStatementService::class)->aging(auth()->user(), $asOf);
    $row = collect($aging['rows'])->firstWhere('client_id', $client->id);
    expect($row['current'])->toBe('236.00')->and($row['d1_30'])->toBe('236.00')->and($row['d31_60'])->toBe('200.00')->and($row['d61_90'])->toBe('236.00')->and($row['d90_plus'])->toBe('236.00')
        ->and($row['total'])->toBe('1144.00')->and($row['total_base'])->toBe('1144.00')->and($aging['totals']['total_base'])->toBe('1144.00');
    $this->get(route('sales.statements.index', ['as_of' => '2026-11-30']))->assertOk()->assertInertia(fn ($p) => $p->component('sales/statements/index')->where('aging.rows.0.client_name', 'Aged SRL')->where('aging.rows.0.d90_plus', '236.00'));
    $this->travelBack();
});

test('historical invoices show their prior payments as a credit row in the statement and the aging', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->post(route('sales.invoices.historical.store'), ['ncf' => 'B0100000777', 'client_id' => $client->id, 'salesperson_id' => '', 'issue_date' => '2026-08-01', 'due_date' => '', 'currency_id' => Currency::base()->id, 'taxable_total' => '10000', 'itbis_total' => '1800', 'total' => '11800', 'paid_amount' => '1800', 'notes' => ''])->assertRedirect();
    $i = Invoice::firstOrFail();
    expect($i->prior_paid_amount)->toBe('1800.00')->and($i->paid_amount)->toBe('1800.00')->and($i->balance)->toBe('10000.00');

    $s = app(ClientStatementService::class)->statement($client, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
    $rows = $s['DOP']['rows'];
    expect($rows)->toHaveCount(2)->and($rows[0]['type'])->toBe('invoice')->and($rows[0]['debit'])->toBe('11800.00')
        ->and($rows[1]['type'])->toBe('prior_payment')->and($rows[1]['type_label'])->toBe('Pagos anteriores')->and($rows[1]['credit'])->toBe('1800.00')->and($rows[1]['balance'])->toBe('10000.00')
        ->and($s['DOP']['closing_balance'])->toBe('10000.00');

    $aging = collect(app(ClientStatementService::class)->aging(auth()->user(), CarbonImmutable::parse('2026-08-15'))['rows'])->firstWhere('client_id', $client->id);
    expect($aging['total'])->toBe('10000.00')->and($aging['current'])->toBe('10000.00');
});

test('aging as of a past date ignores later invoices and payments and refuses a future cut-off', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00'));
    $old = issuedInvoice(['client_id' => $client->id]); // 236.00, due 2026-10-01
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
    $this->post(route('sales.receipts.store'), validReceiptPayload($old, ['receipt_date' => '2026-10-20', 'amount_received' => '100', 'applications' => [['invoice_id' => $old->id, 'amount' => '100']]]))->assertRedirect();
    issuedInvoice(['client_id' => $client->id]); // 236.00, issued 2026-10-20
    $this->travelBack();

    $svc = app(ClientStatementService::class);
    $atSep30 = collect($svc->aging(auth()->user(), CarbonImmutable::parse('2026-09-30'))['rows'])->firstWhere('client_id', $client->id);
    expect($atSep30['total'])->toBe('236.00')->and($atSep30['current'])->toBe('236.00');
    $atOct25 = collect($svc->aging(auth()->user(), CarbonImmutable::parse('2026-10-25'))['rows'])->firstWhere('client_id', $client->id);
    expect($atOct25['total'])->toBe('372.00')->and($atOct25['d1_30'])->toBe('136.00')->and($atOct25['current'])->toBe('236.00');
    $this->get(route('sales.statements.index', ['as_of' => now()->addDay()->toDateString()]))->assertSessionHasErrors(['as_of' => 'La fecha de corte no puede ser futura.']);
});

test('statement lists invoices, notes and payments with a running balance per currency', function () {
    actingAsRole('accounting');
    activeSequence('B01', ['range_to' => 100]);
    activeSequence('B04', ['range_to' => 100]);
    activeSequence('B03', ['range_to' => 100]);
    $client = Client::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    $i = issuedInvoice(['client_id' => $client->id]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00'));
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertRedirect();      // +590.00
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00'));
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i))->assertRedirect();   // −11.80
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));
    $this->post(route('sales.receipts.store'), validReceiptPayload($i->fresh(), ['receipt_date' => '2026-11-05', 'amount_received' => '100', 'applications' => [['invoice_id' => $i->id, 'amount' => '100']]]))->assertRedirect();
    $this->travelBack();

    $s = app(ClientStatementService::class)->statement($client, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    $rows = $s['DOP']['rows'];
    expect($s)->toHaveKey('DOP')->and($s['DOP']['opening_balance'])->toBe('0.00')->and($rows)->toHaveCount(4)
        ->and($rows[0]['type'])->toBe('invoice')->and($rows[0]['debit'])->toBe('236.00')->and($rows[0]['balance'])->toBe('236.00')
        ->and($rows[1]['type'])->toBe('debit_note')->and($rows[1]['balance'])->toBe('826.00')
        ->and($rows[2]['type'])->toBe('credit_note')->and($rows[2]['credit'])->toBe('11.80')->and($rows[2]['balance'])->toBe('814.20')
        ->and($rows[3]['type'])->toBe('payment')->and($rows[3]['credit'])->toBe('100.00')->and($rows[3]['balance'])->toBe('714.20')
        ->and($s['DOP']['closing_balance'])->toBe('714.20');
    $later = app(ClientStatementService::class)->statement($client, CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-30'));
    expect($later['DOP']['opening_balance'])->toBe('826.00')->and($later['DOP']['rows'])->toHaveCount(2);
    $this->get(route('sales.statements.show', [$client, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->assertInertia(fn ($p) => $p->component('sales/statements/show')->where('statement.DOP.closing_balance', '714.20'));
});

test('statements are scoped to the salesperson for sales users', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    $own = Client::factory()->create(['salesperson_id' => $mine->id]);
    $foreign = Client::factory()->create();
    issuedInvoice(['client_id' => $own->id]);
    issuedInvoice(['client_id' => $foreign->id]);
    $this->get(route('sales.statements.index'))->assertInertia(fn ($p) => $p->has('aging.rows', 1));
    $this->get(route('sales.statements.show', $foreign))->assertForbidden();
    $this->get(route('sales.statements.show', $own))->assertOk();
});

test('statement pdf and email use the statement template and the export is logged', function () {
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $client = Client::factory()->create(['email' => 'cliente@x.test']);
    issuedInvoice(['client_id' => $client->id]);
    $this->get(route('sales.statements.pdf', $client))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->post(route('sales.statements.send', $client), ['to' => ['cliente@x.test']])->assertRedirect()->assertSessionHas('success', 'Correo enviado a cliente@x.test');
    expect(SentEmail::first()->template_key)->toBe('statement')->and(SentEmail::first()->subject)->toContain('Estado de cuenta')
        ->and(Activity::inLog('clients')->where('event', 'emailed')->where('subject_id', $client->id)->exists())->toBeTrue();
    $this->get(route('sales.statements.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->latest('id')->first()->properties['table'])->toBe('aging');
});

test('the e-mailed statement uses the period chosen on screen', function () {
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $client = Client::factory()->create(['email' => 'cliente@x.test']);
    issuedInvoice(['client_id' => $client->id]);
    $this->post(route('sales.statements.send', [$client, 'period_from' => '2026-01-01', 'period_to' => '2026-01-31']), ['to' => ['cliente@x.test']])
        ->assertRedirect()->assertSessionHas('success', 'Correo enviado a cliente@x.test');
    expect(SentEmail::first()->template_key)->toBe('statement')
        ->and(Activity::inLog('clients')->where('event', 'emailed')->where('subject_id', $client->id)->exists())->toBeTrue();
    $this->post(route('sales.statements.send', [$client, 'period_from' => '2026-01-31', 'period_to' => '2026-01-01']), ['to' => ['cliente@x.test']])
        ->assertSessionHasErrors(['period_to']);
});
