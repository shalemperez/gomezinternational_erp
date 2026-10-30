<?php

use App\Actions\Sales\CancelInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Sales\InvoiceController;
use App\Models\Invoice;
use App\Models\SentEmail;
use App\Services\Documents\DocumentPdf;
use App\Support\FiscalId;
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

test('issued invoice pdf carries the ncf, type name, sequence expiry and client tax id', function () {
    actingAsRole('owner');
    $i = issuedInvoice();
    $html = app(DocumentPdf::class)->render('pdf.sales.invoice', app(InvoiceController::class)->pdfData($i), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain($i->ncf)->toContain('Factura de Crédito Fiscal')->toContain('Válida hasta')->toContain($i->client_name)->toContain(FiscalId::format($i->client_tax_id))->toContain('Original')->not->toContain('BORRADOR');
    $this->get(route('sales.invoices.pdf', $i))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(Activity::inLog('invoices')->where('event', 'pdf_generated')->where('subject_id', $i->id)->exists())->toBeTrue();
});

test('draft and cancelled invoices are marked on the pdf', function () {
    actingAsRole('owner');
    $draft = Invoice::factory()->withLines(1)->create();
    $html = app(DocumentPdf::class)->render('pdf.sales.invoice', app(InvoiceController::class)->pdfData($draft), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain('BORRADOR')->toContain('Sin valor fiscal');
    $cancelled = issuedInvoice();
    app(CancelInvoice::class)->handle($cancelled, auth()->user(), 'x');
    $html = app(DocumentPdf::class)->render('pdf.sales.invoice', app(InvoiceController::class)->pdfData($cancelled->fresh()), 'x.pdf')->getDomPDF()->outputHtml();
    expect($html)->toContain('ANULADA');
});

test('sending an invoice by email attaches the pdf, sets sent_at and refuses drafts', function () {
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $i = issuedInvoice();
    $this->post(route('sales.invoices.send', $i), ['to' => ['c@x.test']])->assertRedirect()->assertSessionHas('success', 'Correo enviado a c@x.test');
    expect($i->fresh()->sent_at)->not->toBeNull()->and(SentEmail::where('mailable_id', $i->id)->first()->attachments)->toBe([$i->number.'.pdf'])->and(SentEmail::first()->subject)->toContain($i->number);
    Log::shouldReceive('error'); // the exception handler reports the flashed DocumentStateException
    $draft = Invoice::factory()->withLines(1)->create();
    $this->post(route('sales.invoices.send', $draft), ['to' => ['c@x.test']])->assertRedirect()->assertSessionHas('error', 'Solo se pueden enviar facturas emitidas');
});

test('a cancelled invoice cannot be e-mailed', function () {
    actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('error'); // the exception handler reports the flashed DocumentStateException
    $cancelled = issuedInvoice();
    $cancelled->forceFill(['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now()])->save();
    $this->post(route('sales.invoices.send', $cancelled), ['to' => ['c@x.test']])->assertSessionHas('error', 'No se puede enviar una factura anulada');
    expect(SentEmail::where('mailable_id', $cancelled->id)->exists())->toBeFalse();
});

test('users without view-all cannot download or send another salesperson\'s invoice', function () {
    salesUserWithSalesperson();
    $foreign = issuedInvoice();
    $this->get(route('sales.invoices.pdf', $foreign))->assertForbidden();
    $this->post(route('sales.invoices.send', $foreign), ['to' => ['c@x.test']])->assertForbidden();
});
