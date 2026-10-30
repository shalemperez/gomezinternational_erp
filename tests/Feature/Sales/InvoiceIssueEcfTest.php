<?php

use App\Actions\Sales\CancelInvoice;
use App\Actions\Sales\IssueCreditNote;
use App\Actions\Sales\IssueDebitNote;
use App\Actions\Sales\IssueInvoice;
use App\Actions\Sales\SaveInvoice;
use App\Enums\EcfStatus;
use App\Enums\InvoiceStatus;
use App\Enums\NcfType;
use App\Enums\StockMovementType;
use App\Exceptions\DocumentStateException;
use App\Exceptions\EcfCertificateException;
use App\Exceptions\EcfException;
use App\Jobs\SubmitEcfDocument;
use App\Models\AccountingEvent;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\EcfAnnulmentRange;
use App\Models\EcfDocument;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\Salesperson;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('issuing an invoice in ecf mode creates the signed e-cf document, stamps the fiscal mode and dispatches the submission after commit', function () {
    ecfMode();
    activeCertificate();
    Bus::fake([SubmitEcfDocument::class]);
    $actor = User::factory()->create();
    $invoice = issuedInvoice([], $actor);
    $doc = EcfDocument::query()->where('document_type', Invoice::class)->where('document_id', $invoice->id)->firstOrFail();

    expect($invoice->fiscal_mode)->toBe('ecf')->and($invoice->ncf)->toBe('E310000000001')->and($invoice->ncf_type)->toBe(NcfType::E31)->and($invoice->ncf_expires_on)->not->toBeNull()
        ->and($doc->status)->toBe(EcfStatus::Signed)->and($doc->created_by)->toBe($actor->id)->and(strlen($doc->security_code))->toBe(6)
        ->and(Activity::inLog('invoices')->where('subject_id', $invoice->id)->where('event', 'issued')->first()->description)->toBe("Factura {$invoice->number} emitida con e-NCF E310000000001")
        ->and(AccountingEvent::query()->where('type', 'invoice_issued')->where('document_id', $invoice->id)->first()->payload['fiscal_mode'])->toBe('ecf');
    Bus::assertDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($doc) && $j->afterCommit === true);
});

test('issuing in ecf mode is refused and nothing is written when there is no certificate or the xml is invalid', function () {
    ecfMode('dgii');
    $actor = User::factory()->create();
    $draft = app(SaveInvoice::class)->handle(validInvoicePayload(['salesperson_id' => Salesperson::factory()->create()->id]), $actor->givePermissionTo('sales.invoices.view-all'));
    foreach ($draft->lines as $line) {
        app(InventoryService::class)->receive(new StockLine($line->product_id, (int) $draft->warehouse_id, '100', '50'), StockMovementType::Initial, $actor, $actor);
    }
    $next = NcfSequence::query()->where('ncf_type', 'E31')->value('next_number');

    expect(fn () => app(IssueInvoice::class)->handle($draft, $actor))->toThrow(EcfCertificateException::class);
    activeCertificate();
    app(Settings::class)->set('company.rnc', null);
    expect(fn () => app(IssueInvoice::class)->handle($draft->fresh(), $actor))->toThrow(EcfException::class, 'Configura el RNC de la empresa');
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Draft)->and($draft->fresh()->ncf)->toBeNull()
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->value('next_number'))->toBe($next)->and(EcfDocument::count())->toBe(0)
        ->and(Invoice::query()->where('number', 'like', 'FAC-%')->count())->toBe(0);
});

test('credit and debit notes in ecf mode reference the original invoice ncf and create their own e-cf documents', function () {
    $invoice = issuedInvoice(); // traditional B01
    ecfMode();
    activeCertificate();
    Bus::fake([SubmitEcfDocument::class]);
    $actor = User::factory()->create();
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice, ['reason' => 'discount']), $invoice, $actor);
    $debit = app(IssueDebitNote::class)->handle(validDebitNotePayload($invoice->fresh()), $invoice->fresh(), $actor);

    expect($credit->fiscal_mode)->toBe('ecf')->and($credit->ncf)->toBe('E340000000001')->and($credit->modified_ncf)->toBe($invoice->ncf)->and(strlen($credit->modified_ncf))->toBe(11)
        ->and($debit->ncf)->toBe('E330000000001')->and($debit->fiscal_mode)->toBe('ecf')
        ->and(EcfDocument::query()->where('document_type', CreditNote::class)->where('document_id', $credit->id)->value('status'))->toBe(EcfStatus::Signed)
        ->and(EcfDocument::query()->where('document_type', DebitNote::class)->where('document_id', $debit->id)->value('ncf_type'))->toBe(NcfType::E33);
    Bus::assertDispatchedTimes(SubmitEcfDocument::class, 2);
});

test('cancelling an invoice whose e-cf was already sent is refused and points to a credit note', function () {
    ecfMode(outcome: 'in_process');
    activeCertificate();
    $invoice = issuedInvoice(); // the sync queue submits it right away → status Sent
    expect(EcfDocument::query()->where('document_id', $invoice->id)->value('status'))->toBe(EcfStatus::Accepted);
    expect(fn () => app(CancelInvoice::class)->handle($invoice->fresh(), User::factory()->create(), 'error'))
        ->toThrow(DocumentStateException::class, 'Un e-CF ya enviado a la DGII no se puede anular; emite una nota de crédito que lo anule (código de modificación 1).');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

test('cancelling an invoice whose e-cf was never sent marks the e-cf cancelled and queues the number for annulment', function () {
    ecfMode();
    activeCertificate();
    Bus::fake([SubmitEcfDocument::class]);
    $invoice = issuedInvoice();
    $cancelled = app(CancelInvoice::class)->handle($invoice, User::factory()->create(), 'duplicada');
    $doc = EcfDocument::query()->where('document_id', $invoice->id)->firstOrFail();
    expect($cancelled->status)->toBe(InvoiceStatus::Cancelled)->and($doc->status)->toBe(EcfStatus::Cancelled)->and($doc->next_attempt_at)->toBeNull()
        ->and(EcfAnnulmentRange::query()->where('ecf_document_id', $doc->id)->first()->only(['ncf_type', 'environment', 'range_from', 'range_to', 'quantity', 'reason']))
        ->toBe(['ncf_type' => 'E31', 'environment' => 'test', 'range_from' => 1, 'range_to' => 1, 'quantity' => 1, 'reason' => 'Factura anulada antes del envío'])
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'cancelled')->exists())->toBeTrue();
});

test('the issue route flashes the e-ncf and the sales user still cannot cancel', function () {
    ecfMode();
    activeCertificate();
    Bus::fake([SubmitEcfDocument::class]);
    ['user' => $user, 'salesperson' => $salesperson] = salesUserWithSalesperson();
    $client = Client::factory()->create(['salesperson_id' => $salesperson->id]);
    $draft = app(SaveInvoice::class)->handle(validInvoicePayload(['client_id' => $client->id, 'salesperson_id' => $salesperson->id]), $user);
    foreach ($draft->lines as $line) {
        app(InventoryService::class)->receive(new StockLine($line->product_id, (int) $draft->warehouse_id, '100', '50'), StockMovementType::Initial, $user, $user);
    }
    $this->post(route('sales.invoices.issue', $draft))->assertRedirect(route('sales.invoices.show', $draft))->assertSessionHas('success', "Factura {$draft->fresh()->number} emitida con e-NCF E310000000001");
    $this->post(route('sales.invoices.cancel', $draft), ['reason' => 'x'])->assertForbidden();
});
