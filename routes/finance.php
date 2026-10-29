<?php

use App\Http\Controllers\Finance\CurrencyController;
use App\Http\Controllers\Finance\ExchangeRateController;
use App\Http\Controllers\Finance\FinancialAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
    Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');
    Route::post('exchange-rates/fetch', [ExchangeRateController::class, 'fetch'])->name('exchange-rates.fetch');
    Route::put('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update'])->name('exchange-rates.update');
    Route::resource('currencies', CurrencyController::class)->except(['show', 'destroy']);
    Route::get('accounts/export', [FinancialAccountController::class, 'export'])->name('accounts.export');
    Route::resource('accounts', FinancialAccountController::class)->except(['show'])->parameters(['accounts' => 'financialAccount']);
});
