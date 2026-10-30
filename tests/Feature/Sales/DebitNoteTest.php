<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\ImmutableDocumentException;
use App\Models\AccountingEvent;
use App\Models\DebitNote;
use App\Models\Invoice;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    activeSequence('B03', ['range_to' => 100]);
    activeSequence('B01', ['range_to' => 1000]); // issuedInvoice() only creates B01/B02 when no sequence is active
    activeSequence('B02', ['range_to' => 1000]);
});

test('a debit note is issued with B03, increases the invoice balance and records the event', function () {
    $user = actingAsRole('accounting');
    $i = issuedInvoice();
    $i->forceFill(['paid_amount' => '236.00'])->save();
    $i->refreshFinancials();
    expect($i->fresh()->status)->toBe(InvoiceStatus::Paid);

    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertRedirect()->assertSessionHas('success', 'Nota de débito ND-000001 emitida con NCF B0300000001');
    $n = DebitNote::firstOrFail();
    expect($n->ncf)->toBe('B0300000001')->and($n->modified_ncf)->toBe($i->ncf)->and($n->total)->toBe('590.00')->and($n->ncf_expires_on)->not->toBeNull()
        ->and($i->fresh()->debited_amount)->toBe('590.00')->and($i->fresh()->balance)->toBe('590.00')->and($i->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(AccountingEvent::where('type', 'debit_note_issued')->first()->payload['total'])->toBe('590.00')
        ->and(Activity::inLog('debit_notes')->where('event', 'issued')->where('causer_id', $user->id)->exists())->toBeTrue()
        ->and(Activity::inLog('invoices')->where('event', 'debit_note_applied')->where('subject_id', $i->id)->exists())->toBeTrue();
    expect(fn () => $n->delete())->toThrow(ImmutableDocumentException::class);
});

test('debit notes require an open invoice, valid lines and permission', function () {
    actingAsRole('accounting');
    $draft = Invoice::factory()->withLines(1)->create();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($draft))->assertSessionHasErrors(['invoice_id' => 'La factura debe estar emitida.']);
    $i = issuedInvoice();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['lines' => [], 'reason' => 'nope']))->assertSessionHasErrors(['lines', 'reason']);
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i, ['issue_date' => $i->issue_date->subDay()->toDateString()]))->assertSessionHasErrors(['issue_date' => 'La nota no puede ser anterior a la factura.']);
    salesUserWithSalesperson();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertForbidden();
});

test('index, show, pdf and export work and are scoped', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.debit-notes.store'), validDebitNotePayload($i))->assertRedirect();
    $n = DebitNote::firstOrFail();
    $this->get(route('sales.debit-notes.index'))->assertInertia(fn ($p) => $p->component('sales/debit-notes/index')->has('rows.data', 1));
    $this->get(route('sales.debit-notes.show', $n))->assertInertia(fn ($p) => $p->component('sales/debit-notes/show')->where('debitNote.ncf', 'B0300000001'));
    $this->get(route('sales.debit-notes.create', ['invoice' => $i->id]))->assertInertia(fn ($p) => $p->component('sales/debit-notes/form')->where('invoice.id', $i->id));
    $this->get(route('sales.debit-notes.pdf', $n))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('sales.debit-notes.export', ['format' => 'xlsx']))->assertOk();
    salesUserWithSalesperson();
    $this->get(route('sales.debit-notes.show', $n))->assertForbidden();
});
