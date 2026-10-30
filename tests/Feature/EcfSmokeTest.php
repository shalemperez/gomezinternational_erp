<?php

use App\Actions\Sales\IssueCreditNote;
use App\Enums\EcfStatus;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('end-to-end electronic invoicing in fake mode through the http routes', function () {
    Storage::fake('local');
    $owner = actingAsRole('owner');
    activeCertificate($owner);
    companyFiscalSettings();
    foreach (['E31', 'E32', 'E33', 'E34'] as $type) {
        activeSequence($type);
    }
    $this->put(route('admin.ecf-settings.update'), ['fiscal' => ['mode' => 'ecf', 'ecf_cutover_date' => today()->toDateString()], 'ecf' => ['provider' => 'fake', 'environment' => 'test', 'rfce_threshold' => '250000.00', 'company' => ['municipio' => '010100', 'provincia' => '010000'], 'pending_alert_hours' => '24', 'receiver_base_url' => 'https://erp.gomez.test', 'alanube' => ['api_key' => '']]])->assertRedirect();
    $invoice = issuedInvoice([], $owner); // dispatches SubmitEcfDocument (sync queue in tests) → accepted
    $doc = $invoice->ecfDocument;
    expect($invoice->fiscal_mode)->toBe('ecf')->and($doc->status)->toBe(EcfStatus::Accepted)->and($doc->accepted_at)->not->toBeNull();
    $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertInertia(fn ($p) => $p->where('ecf.status', 'accepted')->where('ecf.encf', 'E310000000001'));
    $this->get(route('sales.invoices.pdf', $invoice))->assertOk();
    $this->get(route('fiscal.ecf-documents.show', $doc))->assertOk();
    $this->get(route('fiscal.ecf-documents.xml', $doc))->assertOk();
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice->fresh(), ['reason' => 'discount']), $invoice->fresh(), $owner);
    expect($credit->ncf)->toBe('E340000000001')->and($credit->ecfDocument->status)->toBe(EcfStatus::Accepted);
    $this->get(route('fiscal.ecf-documents.index'))->assertInertia(fn ($p) => $p->has('rows.data', 2));
    $this->get(route('fiscal.certification.index'))->assertOk()->assertInertia(fn ($p) => $p->where('stats.accepted', 2));
    $this->get(route('fiscal.ecf-annulments.index'))->assertOk();
    $this->get(route('fiscal.received-ecfs.index'))->assertOk();
    expect(Activity::inLog('ecf_documents')->where('event', 'accepted')->count())->toBe(2);
});
