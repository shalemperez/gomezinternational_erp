<?php

use App\Actions\Sales\CancelInvoice;
use App\Models\Client;
use App\Models\Invoice;
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

function openInvoiceDue(Client $client, string $dueDate, string $paid = '0'): Invoice
{
    $i = issuedInvoice(['client_id' => $client->id]);
    Invoice::query()->whereKey($i->id)->update(['due_date' => $dueDate, 'paid_amount' => $paid]); // query builder: bypasses the immutability guard on purpose (test fixture)
    $i = $i->fresh();
    $i->refreshFinancials();

    return $i->fresh();
}

test('aging buckets open balances by days overdue and sums in base currency', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['business_name' => 'Aged SRL']);
    $asOf = CarbonImmutable::parse('2026-11-30');
    openInvoiceDue($client, '2026-12-05');            // current
    openInvoiceDue($client, '2026-11-15');            // 15 days
    openInvoiceDue($client, '2026-10-15', '36.00');   // 46 days, balance 200
    openInvoiceDue($client, '2026-09-10');            // 81 days
    openInvoiceDue($client, '2026-07-01');            // 152 days
    $cancelled = openInvoiceDue($client, '2026-07-01');
    app(CancelInvoice::class)->handle($cancelled, auth()->user(), 'x');

    $aging = app(ClientStatementService::class)->aging(auth()->user(), $asOf);
    $row = collect($aging['rows'])->firstWhere('client_id', $client->id);
    expect($row['current'])->toBe('236.00')->and($row['d1_30'])->toBe('236.00')->and($row['d31_60'])->toBe('200.00')->and($row['d61_90'])->toBe('236.00')->and($row['d90_plus'])->toBe('236.00')
        ->and($row['total'])->toBe('1144.00')->and($row['total_base'])->toBe('1144.00')->and($aging['totals']['total_base'])->toBe('1144.00');
    $this->get(route('sales.statements.index', ['as_of' => '2026-11-30']))->assertOk()->assertInertia(fn ($p) => $p->component('sales/statements/index')->where('aging.rows.0.client_name', 'Aged SRL')->where('aging.rows.0.d90_plus', '236.00'));
});

test('statement lists invoices, notes and payments with a running balance per currency', function () {
    actingAsRole('accounting');
    activeSequence('B01', ['range_to' => 100]);
    activeSequence('B04', ['range_to' => 100]);
    activeSequence('B03', ['range_to' => 100]);
    $client = Client::factory()->create();
    $i = issuedInvoice(['client_id' => $client->id, 'issue_date' => '2026-11-02']);
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['issue_date' => '2026-11-03']))->assertRedirect();      // +590.00
    $this->post(route('sales.credit-notes.store'), validCreditNotePayload($i, ['issue_date' => '2026-11-04']))->assertRedirect();   // −11.80
    $this->post(route('sales.receipts.store'), validReceiptPayload($i->fresh(), ['receipt_date' => '2026-11-05', 'amount_received' => '100', 'applications' => [['invoice_id' => $i->id, 'amount' => '100']]]))->assertRedirect();

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
