<?php

use App\Http\Controllers\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('warehouses/export', [WarehouseController::class, 'export'])->name('warehouses.export');
    Route::resource('warehouses', WarehouseController::class)->except(['show']);
});
