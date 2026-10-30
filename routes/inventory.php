<?php

use App\Http\Controllers\Inventory\DispatchController;
use App\Http\Controllers\Inventory\InitialStockController;
use App\Http\Controllers\Inventory\LotController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('warehouses/export', [WarehouseController::class, 'export'])->name('warehouses.export');
    Route::resource('warehouses', WarehouseController::class)->except(['show']);
    Route::get('dispatches/export', [DispatchController::class, 'export'])->name('dispatches.export');
    Route::get('dispatches/{dispatch}/pdf', [DispatchController::class, 'pdf'])->name('dispatches.pdf');
    Route::post('dispatches/{dispatch}/confirm', [DispatchController::class, 'confirm'])->name('dispatches.confirm');
    Route::resource('dispatches', DispatchController::class);
    Route::get('initial-stock', [InitialStockController::class, 'create'])->name('initial-stock.create');
    Route::post('initial-stock', [InitialStockController::class, 'store'])->name('initial-stock.store');
    Route::get('stock/export', [StockController::class, 'export'])->name('stock.export');
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('movements/export', [StockMovementController::class, 'export'])->name('movements.export');
    Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');
    Route::get('lots', [LotController::class, 'index'])->name('lots.index');
});
