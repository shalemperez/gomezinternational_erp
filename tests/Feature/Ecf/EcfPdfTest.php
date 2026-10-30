<?php

use App\Actions\Sales\CancelInvoice;
use App\Actions\Sales\IssueCreditNote;
use App\Http\Controllers\Sales\CreditNoteController;
use App\Http\Controllers\Sales\InvoiceController;
use App\Jobs\SubmitEcfDocument;
use App\Models\Client;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Documents\DocumentPdf;
use App\Services\Ecf\EcfPdfData;
use App\Services\Ecf\EcfService;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    Bus::fake([SubmitEcfDocument::class]);
});

test('the e-cf pdf data carries the e-ncf, qr, security code and signing time and omits the expiry for consumo', function () {
    ecfMode();
    activeCertificate();
    $credit = issuedInvoice();
    $consumer = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $consumo = issuedInvoice(['client_id' => $consumer->id]);
    $a = EcfPdfData::for($credit);
    $b = EcfPdfData::for($consumo);
    expect($a['encf'])->toBe('E310000000001')->and($a['type_label'])->toBe('Factura de Crédito Fiscal Electrónica')->and($a['expires_on'])->toMatch('/^\d{2}\/\d{2}\/\d{4}$/')
        ->and($a['qr_png'])->toStartWith('data:image/png;base64,')->and(strlen($a['security_code']))->toBe(6)->and($a['signed_at'])->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/')
        ->and($a['legend'])->toBe('e-CF emitido en modalidad de Contingencia, el cual podrá ser consultado para su validez fiscal, a partir de las setenta y dos (72) horas.')
        ->and($b['encf'])->toBe('E320000000001')->and($b['expires_on'])->toBeNull()->and($b['type_label'])->toBe('Factura de Consumo Electrónica');
});

test('the printed representation shows the contingency legend until the document is accepted', function () {
    ecfMode();
    activeCertificate();
    $invoice = issuedInvoice();
    $controller = app(InvoiceController::class);
    $before = view('pdf.sales.invoice', [...$controller->pdfData($invoice), 'company' => app(DocumentPdf::class)->company(), 'fileName' => 'x.pdf'])->render();
    expect($before)->toContain('modalidad de Contingencia')->toContain('Código de seguridad:')->toContain('e-NCF: E310000000001')->toContain('Factura de Crédito Fiscal Electrónica')->toContain('data:image/png;base64,');
    app(EcfService::class)->submit($invoice->ecfDocument);
    $after = view('pdf.sales.invoice', [...$controller->pdfData($invoice->fresh()), 'company' => app(DocumentPdf::class)->company(), 'fileName' => 'x.pdf'])->render();
    expect($after)->not->toContain('modalidad de Contingencia')->toContain('Código de seguridad:')->toContain('Fecha de firma digital:');
});

test('rejected and cancelled e-cf print their invalidity', function () {
    ecfMode(outcome: 'rejected');
    activeCertificate();
    $invoice = issuedInvoice();
    app(EcfService::class)->submit($invoice->ecfDocument);
    expect(EcfPdfData::for($invoice->fresh())['legend'])->toBe('RECHAZADO POR LA DGII — sin validez fiscal')->and(EcfPdfData::for($invoice->fresh())['is_valid'])->toBeFalse();
    $other = issuedInvoice();
    app(CancelInvoice::class)->handle($other, User::factory()->create(), 'dup');
    expect(EcfPdfData::for($other->fresh())['legend'])->toBe('ANULADO');
});

test('the pdf route streams the electronic representation and logs it', function () {
    ecfMode();
    activeCertificate();
    actingAsRole('accounting');
    $invoice = issuedInvoice();
    $this->get(route('sales.invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(Activity::inLog('invoices')->where('subject_id', $invoice->id)->where('event', 'pdf_generated')->exists())->toBeTrue();
});

test('credit notes print the modified e-ncf and the modification code in words', function () {
    ecfMode();
    activeCertificate();
    $invoice = issuedInvoice();
    $credit = app(IssueCreditNote::class)->handle(validCreditNotePayload($invoice, ['reason' => 'discount']), $invoice, User::factory()->create());
    $data = EcfPdfData::for($credit);
    expect($data['modified_ncf'])->toBe('E310000000001')->and($data['modification_label'])->toBe('3 - Corrige montos del NCF modificado')->and($data['expires_on'])->toBeNull();
    $html = view('pdf.sales.credit-note', [...app(CreditNoteController::class)->pdfData($credit), 'company' => app(DocumentPdf::class)->company(), 'fileName' => 'x.pdf'])->render();
    expect($html)->toContain('e-NCF modificado: E310000000001')->toContain('Código de modificación: 3 - Corrige montos del NCF modificado')->toContain('Nota de Crédito Electrónica');
});

test('exempt lines are marked with E and the unit is printed in words', function () {
    ecfMode();
    activeCertificate();
    $unit = UnitOfMeasure::factory()->create(['code' => 'CAJ', 'name' => 'Caja']);
    $exempt = Product::factory()->create(['itbis_rate' => '0.00', 'unit_of_measure_id' => $unit->id, 'name' => 'Arroz selecto']);
    $invoice = issuedInvoice(['lines' => [['product_id' => $exempt->id, 'description' => 'Arroz selecto', 'quantity' => '1', 'unit_price' => '100', 'discount_percent' => '0']]]);
    $html = view('pdf.sales.invoice', [...app(InvoiceController::class)->pdfData($invoice), 'company' => app(DocumentPdf::class)->company(), 'fileName' => 'x.pdf'])->render();
    expect($html)->toContain('E Arroz selecto')->toContain('<th>Unidad</th>')->toContain('<td>Caja</td>');
});

test('traditional invoices keep their pdf without the electronic stamp', function () {
    $invoice = issuedInvoice();
    expect(EcfPdfData::for($invoice))->toBeNull();
    $html = view('pdf.sales.invoice', [...app(InvoiceController::class)->pdfData($invoice), 'company' => app(DocumentPdf::class)->company(), 'fileName' => 'x.pdf'])->render();
    expect($html)->not->toContain('Código de seguridad')->not->toContain('<th>Unidad</th>')->toContain('NCF: '.$invoice->ncf);
});
