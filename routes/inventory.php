<?php

use App\Http\Controllers\Inventory\InitialStockController;
use App\Http\Controllers\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('warehouses/export', [WarehouseController::class, 'export'])->name('warehouses.export');
    Route::resource('warehouses', WarehouseController::class)->except(['show']);
    Route::get('initial-stock', [InitialStockController::class, 'create'])->name('initial-stock.create');
    Route::post('initial-stock', [InitialStockController::class, 'store'])->name('initial-stock.store');
    Route::get('stock', fn () => Inertia::render('dashboard'))->name('stock.index');
});
