<?php

use App\Http\Controllers\Sales\CreditNoteController;
use App\Http\Controllers\Sales\DebitNoteController;
use App\Http\Controllers\Sales\InvoiceController;
use App\Http\Controllers\Sales\ProformaController;
use App\Http\Controllers\Sales\ReceiptController;
use App\Http\Controllers\Sales\StatementController;
use App\Http\Controllers\Sales\TotalsPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('sales')->name('sales.')->group(function () {
    Route::post('totals/preview', TotalsPreviewController::class)->name('totals.preview');

    Route::get('invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('proformas/{proforma}/invoice', [InvoiceController::class, 'fromProforma'])->name('proformas.invoice');
    Route::resource('invoices', InvoiceController::class);

    Route::get('credit-notes/export', [CreditNoteController::class, 'export'])->name('credit-notes.export');
    Route::get('credit-notes/{creditNote}/pdf', [CreditNoteController::class, 'pdf'])->name('credit-notes.pdf');
    Route::resource('credit-notes', CreditNoteController::class)->only(['index', 'create', 'store', 'show'])->parameters(['credit-notes' => 'creditNote']);

    Route::get('debit-notes/export', [DebitNoteController::class, 'export'])->name('debit-notes.export');
    Route::get('debit-notes/{debitNote}/pdf', [DebitNoteController::class, 'pdf'])->name('debit-notes.pdf');
    Route::resource('debit-notes', DebitNoteController::class)->only(['index', 'create', 'store', 'show'])->parameters(['debit-notes' => 'debitNote']);

    Route::get('receipts/export', [ReceiptController::class, 'export'])->name('receipts.export');
    Route::get('receipts/{receipt}/pdf', [ReceiptController::class, 'pdf'])->name('receipts.pdf');
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel'])->name('receipts.cancel');
    Route::post('receipts/{receipt}/apply', [ReceiptController::class, 'apply'])->name('receipts.apply');
    Route::resource('receipts', ReceiptController::class)->only(['index', 'create', 'store', 'show']);

    Route::get('statements', [StatementController::class, 'index'])->name('statements.index');
    Route::get('statements/export', [StatementController::class, 'export'])->name('statements.export');
    Route::get('statements/{client}', [StatementController::class, 'show'])->name('statements.show');
    Route::get('statements/{client}/pdf', [StatementController::class, 'pdf'])->name('statements.pdf');
    Route::post('statements/{client}/send', [StatementController::class, 'send'])->name('statements.send');

    Route::get('proformas/export', [ProformaController::class, 'export'])->name('proformas.export');
    Route::get('proformas/{proforma}/pdf', [ProformaController::class, 'pdf'])->name('proformas.pdf');
    Route::post('proformas/{proforma}/send', [ProformaController::class, 'send'])->name('proformas.send');
    Route::post('proformas/{proforma}/mark-sent', [ProformaController::class, 'markSent'])->name('proformas.mark-sent');
    Route::post('proformas/{proforma}/approve', [ProformaController::class, 'approve'])->name('proformas.approve');
    Route::post('proformas/{proforma}/reject', [ProformaController::class, 'reject'])->name('proformas.reject');
    Route::post('proformas/{proforma}/duplicate', [ProformaController::class, 'duplicate'])->name('proformas.duplicate');
    Route::resource('proformas', ProformaController::class);
});
