<?php

use App\Http\Controllers\Fiscal\EcfAnnulmentController;
use App\Http\Controllers\Fiscal\EcfDocumentController;
use App\Http\Controllers\Fiscal\ReceivedEcfController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('fiscal')->name('fiscal.')->group(function () {
    Route::get('ecf-documents/export', [EcfDocumentController::class, 'export'])->name('ecf-documents.export');
    Route::get('ecf-documents', [EcfDocumentController::class, 'index'])->name('ecf-documents.index');
    Route::get('ecf-documents/{ecfDocument}', [EcfDocumentController::class, 'show'])->name('ecf-documents.show');
    Route::post('ecf-documents/{ecfDocument}/retry', [EcfDocumentController::class, 'retry'])->name('ecf-documents.retry');
    Route::post('ecf-documents/{ecfDocument}/poll', [EcfDocumentController::class, 'poll'])->name('ecf-documents.poll');
    Route::get('ecf-documents/{ecfDocument}/xml', [EcfDocumentController::class, 'xml'])->name('ecf-documents.xml');
    Route::get('ecf-documents/{ecfDocument}/transmissions/{transmission}', [EcfDocumentController::class, 'transmission'])->name('ecf-documents.transmission');
    Route::get('ecf-annulments', [EcfAnnulmentController::class, 'index'])->name('ecf-annulments.index');
    Route::post('ecf-annulments/ranges', [EcfAnnulmentController::class, 'storeRange'])->name('ecf-annulments.ranges.store');
    Route::delete('ecf-annulments/ranges/{range}', [EcfAnnulmentController::class, 'destroyRange'])->name('ecf-annulments.ranges.destroy');
    Route::post('ecf-annulments/send', [EcfAnnulmentController::class, 'send'])->name('ecf-annulments.send');
    Route::get('received-ecfs/export', [ReceivedEcfController::class, 'export'])->name('received-ecfs.export');
    Route::get('received-ecfs', [ReceivedEcfController::class, 'index'])->name('received-ecfs.index');
    Route::get('received-ecfs/{receivedEcf}', [ReceivedEcfController::class, 'show'])->name('received-ecfs.show');
    Route::get('received-ecfs/{receivedEcf}/xml', [ReceivedEcfController::class, 'xml'])->name('received-ecfs.xml');
});
