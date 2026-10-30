<?php

use App\Http\Controllers\Sales\ReceiptController;
use App\Models\Receipt;
use App\Services\Documents\DocumentPdf;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('receipt pdf lists the applied invoices, retentions and advance', function () {
    actingAsRole('accounting');
    $i = issuedInvoice();
    $this->post(route('sales.receipts.store'), validReceiptPayload($i, ['amount_received' => '250', 'retention_itbis' => '36', 'applications' => [['invoice_id' => $i->id, 'amount' => '236']]]))->assertRedirect();
    $r = Receipt::firstOrFail();
    $html = app(DocumentPdf::class)->render('pdf.sales.receipt', app(ReceiptController::class)->pdfData($r), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain('RECIBO DE INGRESO')->toContain($r->number)->toContain($i->ncf)->toContain('Retención ITBIS')->toContain('RD$ 36.00')->toContain('Saldo a favor')->toContain('RD$ 50.00');
    $this->get(route('sales.receipts.pdf', $r))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(Activity::inLog('receipts')->where('event', 'pdf_generated')->exists())->toBeTrue();
    $this->get(route('sales.invoices.show', $i))->assertInertia(fn ($p) => $p->has('applications', 1)->where('applications.0.receipt_number', $r->number));
});
