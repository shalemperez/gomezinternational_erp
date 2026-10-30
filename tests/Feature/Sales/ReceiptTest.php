<?php

use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\ImmutableDocumentException;
use App\Models\AccountingEvent;
use App\Models\Client;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\Receipt;
use App\Models\ReceiptApplication;
use App\Services\Sales\ClientAccount;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('a receipt applied to one invoice pays it and records the event', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($i))->assertRedirect()->assertSessionHas('success', 'Recibo REC-000001 registrado');
    $r = Receipt::firstOrFail();
    expect($r->status)->toBe(ReceiptStatus::Posted)->and($r->number)->toBe('REC-000001')->and($r->posted_at)->not->toBeNull()->and($r->applied_total)->toBe('236.00')->and($r->unapplied_amount)->toBe('0.00')
        ->and($r->salesperson_id)->toBe($i->client->salesperson_id)->and($i->fresh()->paid_amount)->toBe('236.00')->and($i->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(ReceiptApplication::count())->toBe(1)->and(ReceiptApplication::first()->source)->toBe('receipt')
        ->and(AccountingEvent::where('type', 'receipt_posted')->first()->payload['applications'][0]['ncf'])->toBe($i->ncf)
        ->and(Activity::inLog('receipts')->where('event', 'posted')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'payment_applied')->where('subject_id', $i->id)->exists())->toBeTrue();
    expect(fn () => $r->update(['reference' => 'x']))->toThrow(ImmutableDocumentException::class);
});

test('partial payments, retentions and an unapplied remainder become an advance', function () {
    actingAsRole('accounting');
    $a = issuedInvoice(); // 236.00, itbis 36.00
    $b = issuedInvoice(['client_id' => $a->client_id]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '300', 'retention_itbis' => '36', 'retention_isr' => '0', 'applications' => [['invoice_id' => $a->id, 'amount' => '236'], ['invoice_id' => $b->id, 'amount' => '50']]]))->assertRedirect();
    $r = Receipt::firstOrFail();
    expect($r->applied_total)->toBe('286.00')->and($r->unapplied_amount)->toBe('50.00')->and($r->retention_itbis)->toBe('36.00')
        ->and($a->fresh()->status)->toBe(InvoiceStatus::Paid)->and($b->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)->and($b->fresh()->balance)->toBe('186.00')
        ->and(ClientAccount::advanceBalance($a->client, $a->currency))->toBe('50.00');
});

test('applications cannot exceed the invoice balance or the receipt amount and must match client and currency', function () {
    actingAsRole('accounting');
    $a = issuedInvoice();
    $other = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['applications' => [['invoice_id' => $a->id, 'amount' => '300']]]))->assertSessionHasErrors(['applications.0.amount' => "La aplicación supera el saldo de la factura {$a->number} (236.00)."]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '100', 'applications' => [['invoice_id' => $a->id, 'amount' => '200']]]))->assertSessionHasErrors(['applications' => 'Las aplicaciones (200.00) superan el monto del recibo (100.00).']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['applications' => [['invoice_id' => $other->id, 'amount' => '10']]]))->assertSessionHasErrors(['applications.0.invoice_id' => "La factura {$other->number} es de otro cliente."]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['retention_itbis' => '40']))->assertSessionHasErrors(['retention_itbis' => 'La retención de ITBIS supera el ITBIS de las facturas aplicadas.']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['payment_method' => 'advance']))->assertSessionHasErrors(['payment_method']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['financial_account_id' => FinancialAccount::factory()->create(['is_active' => false])->id]))->assertSessionHasErrors(['financial_account_id' => 'La cuenta no existe o está inactiva.']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '0', 'retention_itbis' => '0', 'applications' => []]))->assertSessionHasErrors(['amount_received' => 'El recibo debe tener un monto o una retención.']);
    expect(Receipt::count())->toBe(0);
});

test('money inputs accept at most two decimals', function () {
    actingAsRole('accounting');
    $a = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['applications' => [['invoice_id' => $a->id, 'amount' => '0.004']]]))->assertSessionHasErrors(['applications.0.amount' => 'Usa como máximo dos decimales.']);
    expect(Receipt::count())->toBe(0);
});

test('an advance is later applied to another invoice and the receipt can be cancelled reversing everything', function () {
    $user = actingAsRole('accounting');
    $a = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($a, ['amount_received' => '500', 'applications' => [['invoice_id' => $a->id, 'amount' => '236']]]))->assertRedirect();
    $r = Receipt::firstOrFail();
    $b = issuedInvoice(['client_id' => $a->client_id]);
    $this->post(route('sales.receipts.apply', $r), ['applications' => [['invoice_id' => $b->id, 'amount' => '300']]])->assertSessionHasErrors(['applications' => 'Las aplicaciones (300.00) superan el anticipo disponible (264.00).']);
    $this->post(route('sales.receipts.apply', $r), ['applications' => [['invoice_id' => $b->id, 'amount' => '200']]])->assertRedirect()->assertSessionHas('success', 'Anticipo aplicado: RD$ 200.00');
    expect($r->fresh()->unapplied_amount)->toBe('64.00')->and($r->fresh()->applied_total)->toBe('436.00')->and($b->fresh()->balance)->toBe('36.00')
        ->and(ReceiptApplication::where('source', 'advance')->count())->toBe(1)
        ->and(AccountingEvent::where('type', 'advance_applied')->count())->toBe(1)
        ->and(AccountingEvent::where('type', 'advance_applied')->first()->payload['applied_total'])->toBe('200.00')
        ->and(Activity::inLog('receipts')->where('event', 'advance_applied')->where('subject_id', $r->id)->exists())->toBeTrue();

    $this->post(route('sales.receipts.cancel', $r), ['reason' => 'Transferencia rebotada'])->assertRedirect()->assertSessionHas('success', 'Recibo REC-000001 anulado');
    expect($r->fresh()->status)->toBe(ReceiptStatus::Cancelled)->and($r->fresh()->cancelled_by)->toBe($user->id)->and($r->fresh()->unapplied_amount)->toBe('0.00')
        ->and($a->fresh()->status)->toBe(InvoiceStatus::Issued)->and($a->fresh()->paid_amount)->toBe('0.00')->and($b->fresh()->balance)->toBe('236.00')
        ->and(AccountingEvent::where('type', 'receipt_cancelled')->first()->payload['reversed'])->toHaveCount(2)
        ->and(Activity::inLog('receipts')->where('event', 'cancelled')->exists())->toBeTrue();
    $this->post(route('sales.receipts.cancel', $r), ['reason' => 'x'])->assertSessionHas('error', 'El recibo ya está anulado');
    $this->post(route('sales.receipts.apply', $r), ['applications' => [['invoice_id' => $b->id, 'amount' => '1']]])->assertSessionHas('error', 'El recibo está anulado');
});

test('usd receipts store the rate of the receipt date and reject invoices in another currency', function () {
    actingAsRole('accounting');
    $usd = Currency::where('code', 'USD')->firstOrFail();
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->toDateString(), 'rate' => '61.000000']);
    $dop = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($dop, ['currency_id' => $usd->id]))->assertSessionHasErrors(['applications.0.invoice_id' => "La factura {$dop->number} está en otra moneda."]);
    $usdInvoice = issuedInvoice(['currency_id' => $usd->id, 'client_id' => $dop->client_id]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($usdInvoice, ['currency_id' => $usd->id]))->assertRedirect();
    expect(Receipt::firstOrFail()->exchange_rate)->toBe('61.000000')->and($usdInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('permissions and scoping: sales can view own receipts but not create or cancel', function () {
    ['salesperson' => $sp] = salesUserWithSalesperson();
    $own = issuedInvoice(['client_id' => Client::factory()->create(['salesperson_id' => $sp->id])->id]);
    $this->post(route('sales.receipts.store'), validReceiptPayload($own))->assertForbidden();
    actingAsRole('accounting');
    $this->post(route('sales.receipts.store'), validReceiptPayload($own))->assertRedirect();
    $r = Receipt::firstOrFail();
    $this->post(route('sales.receipts.cancel', $r), ['reason' => ''])->assertSessionHasErrors(['reason']);
    salesUserWithSalesperson();
    $this->get(route('sales.receipts.show', $r))->assertForbidden();
    $this->get(route('sales.receipts.index'))->assertInertia(fn ($p) => $p->has('rows.data', 0));
    actingAsRole('management');
    $this->get(route('sales.receipts.index'))->assertInertia(fn ($p) => $p->component('sales/receipts/index')->has('rows.data', 1));
    $this->get(route('sales.receipts.show', $r))->assertInertia(fn ($p) => $p->component('sales/receipts/show')->has('receipt.applications', 1)->where('receipt.financial_account.name', $r->financialAccount->name)->has('history'));
    $this->get(route('sales.receipts.export', ['format' => 'csv']))->assertOk();
});
