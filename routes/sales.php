<?php

use App\Http\Controllers\Sales\InvoiceController;
use App\Http\Controllers\Sales\ProformaController;
use App\Http\Controllers\Sales\TotalsPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('sales')->name('sales.')->group(function () {
    Route::post('totals/preview', TotalsPreviewController::class)->name('totals.preview');

    Route::get('invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('proformas/{proforma}/invoice', [InvoiceController::class, 'fromProforma'])->name('proformas.invoice');
    Route::resource('invoices', InvoiceController::class);

    Route::get('proformas/export', [ProformaController::class, 'export'])->name('proformas.export');
    Route::get('proformas/{proforma}/pdf', [ProformaController::class, 'pdf'])->name('proformas.pdf');
    Route::post('proformas/{proforma}/send', [ProformaController::class, 'send'])->name('proformas.send');
    Route::post('proformas/{proforma}/mark-sent', [ProformaController::class, 'markSent'])->name('proformas.mark-sent');
    Route::post('proformas/{proforma}/approve', [ProformaController::class, 'approve'])->name('proformas.approve');
    Route::post('proformas/{proforma}/reject', [ProformaController::class, 'reject'])->name('proformas.reject');
    Route::post('proformas/{proforma}/duplicate', [ProformaController::class, 'duplicate'])->name('proformas.duplicate');
    Route::resource('proformas', ProformaController::class);
});
