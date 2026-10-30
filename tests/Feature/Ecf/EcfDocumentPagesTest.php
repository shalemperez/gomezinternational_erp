<?php

use App\Actions\Sales\IssueCreditNote;
use App\Actions\Sales\IssueDebitNote;
use App\Enums\EcfStatus;
use App\Models\Client;
use App\Models\User;
use App\Services\Settings;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    ecfMode();
    activeCertificate();
});

test('the e-cf index lists documents with filters and hides other salespeople rows from sales users', function () {
    $mine = salesUserWithSalesperson();
    $client = Client::factory()->create(['salesperson_id' => $mine['salesperson']->id]);
    $own = issuedInvoice(['client_id' => $client->id, 'salesperson_id' => $mine['salesperson']->id]);
    $other = issuedInvoice();
    $this->get(route('fiscal.ecf-documents.index'))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/ecf-documents/index')->has('rows.data', 1)->where('rows.data.0.encf', $own->ncf)->where('rows.data.0.document.number', $own->number));
    actingAsRole('accounting');
    $this->get(route('fiscal.ecf-documents.index', ['status' => 'accepted']))->assertInertia(fn ($p) => $p->has('rows.data', 2));
    $this->get(route('fiscal.ecf-documents.index', ['status' => 'rejected']))->assertInertia(fn ($p) => $p->has('rows.data', 0));
    $this->get(route('fiscal.ecf-documents.index', ['search' => $other->ncf]))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.document.kind', 'invoice'));
});

test('the show page exposes status, transmissions and history and the sales user is scoped', function () {
    actingAsRole('accounting');
    $invoice = issuedInvoice();
    $doc = $invoice->ecfDocument; // already accepted by the sync job
    $this->get(route('fiscal.ecf-documents.show', $doc))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/ecf-documents/show')
        ->where('ecfDocument.encf', $doc->encf)->where('ecfDocument.status', 'accepted')->where('ecfDocument.status_label', 'Aceptado')->where('ecfDocument.document.number', $invoice->number)
        ->has('transmissions', 1)->where('transmissions.0.kind', 'send')->where('can.manage', true)->has('history'));
    $sales = salesUserWithSalesperson();
    $this->get(route('fiscal.ecf-documents.show', $doc))->assertForbidden();
});

test('retry and poll require the manage permission and run synchronously', function () {
    ecfMode(outcome: 'unavailable'); // the automatic submission fails → the document stays signed
    $invoice = issuedInvoice();
    $doc = $invoice->ecfDocument;
    expect($doc->status)->toBe(EcfStatus::Signed)->and($doc->attempts)->toBe(1);
    actingAsRole('sales');
    $this->post(route('fiscal.ecf-documents.retry', $doc))->assertForbidden();
    actingAsRole('accounting');
    $this->post(route('fiscal.ecf-documents.poll', $doc))->assertRedirect()->assertSessionHas('error', 'Solo se puede consultar el estado de e-CF enviados');
    app(Settings::class)->set('ecf.fake.outcome', 'in_process');
    $this->post(route('fiscal.ecf-documents.retry', $doc))->assertRedirect()->assertSessionHas('success', 'Reenvío ejecutado: Aceptado'); // the sync queue runs the chained poll at once
    expect($doc->fresh()->status)->toBe(EcfStatus::Accepted);
    $this->post(route('fiscal.ecf-documents.retry', $doc))->assertSessionHas('error', 'Solo se pueden reenviar e-CF pendientes de envío');
    $this->post(route('fiscal.ecf-documents.poll', $doc))->assertSessionHas('error', 'Solo se puede consultar el estado de e-CF enviados');
});

test('xml download streams the signed file and the rfce when present', function () {
    actingAsRole('accounting');
    $consumer = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $invoice = issuedInvoice(['client_id' => $consumer->id]);
    $doc = $invoice->ecfDocument;
    $this->get(route('fiscal.ecf-documents.xml', $doc))->assertOk()->assertDownload('131880738E320000000001.xml');
    $this->get(route('fiscal.ecf-documents.xml', [$doc, 'file' => 'rfce']))->assertOk()->assertDownload('RFCE-131880738E320000000001.xml');
    $credit = issuedInvoice();
    $this->get(route('fiscal.ecf-documents.xml', [$credit->ecfDocument, 'file' => 'rfce']))->assertNotFound();
});

test('export is limited to the export permission and logged', function () {
    issuedInvoice();
    actingAsRole('sales');
    $this->get(route('fiscal.ecf-documents.export'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('fiscal.ecf-documents.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->where('properties->table', 'ecf-documents')->exists())->toBeTrue();
});

test('invoice, credit note and debit note show pages include the e-cf summary without file contents', function () {
    actingAsRole('accounting');
    $invoice = issuedInvoice();
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice, ['reason' => 'discount']), $invoice, User::factory()->create());
    $debit = app(IssueDebitNote::class)->handle(validDebitNotePayload($invoice->fresh()), $invoice->fresh(), User::factory()->create());
    $this->get(route('sales.invoices.show', $invoice))->assertInertia(fn ($p) => $p->where('ecf.encf', 'E310000000001')->where('ecf.status_label', 'Aceptado')->missing('ecf.xml'));
    $this->get(route('sales.credit-notes.show', $credit))->assertInertia(fn ($p) => $p->where('ecf.encf', 'E340000000001'));
    $this->get(route('sales.debit-notes.show', $debit))->assertInertia(fn ($p) => $p->where('ecf.encf', 'E330000000001'));
    app(Settings::class)->set('fiscal.mode', 'ncf');
    $this->get(route('sales.invoices.show', issuedInvoice()))->assertInertia(fn ($p) => $p->where('ecf', null));
});

test('a transmission response is returned as json to users who can view the document', function () {
    actingAsRole('accounting');
    $invoice = issuedInvoice();
    $doc = $invoice->ecfDocument;
    $transmission = $doc->transmissions()->firstOrFail();
    $this->get(route('fiscal.ecf-documents.transmission', [$doc, $transmission]))->assertOk()->assertJsonPath('response.fake', true)->assertJsonPath('http_status', 200);
});
