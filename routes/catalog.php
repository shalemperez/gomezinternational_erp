<?php

use App\Http\Controllers\Catalog\AdditionalTaxController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\ProductCategoryController;
use App\Http\Controllers\Catalog\SalespersonController;
use App\Http\Controllers\Catalog\UnitOfMeasureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('catalog')->name('catalog.')->group(function () {
    Route::get('brands/export', [BrandController::class, 'export'])->name('brands.export');
    Route::resource('brands', BrandController::class)->except(['show']);

    Route::get('categories/export', [ProductCategoryController::class, 'export'])->name('categories.export');
    Route::resource('categories', ProductCategoryController::class)->except(['show'])->parameters(['categories' => 'category']);

    Route::get('units/export', [UnitOfMeasureController::class, 'export'])->name('units.export');
    Route::resource('units', UnitOfMeasureController::class)->except(['show'])->parameters(['units' => 'unit']);

    Route::get('additional-taxes/export', [AdditionalTaxController::class, 'export'])->name('additional-taxes.export');
    Route::resource('additional-taxes', AdditionalTaxController::class)->except(['show'])->parameters(['additional-taxes' => 'additionalTax']);

    Route::get('salespeople/export', [SalespersonController::class, 'export'])->name('salespeople.export');
    Route::resource('salespeople', SalespersonController::class)->except(['show'])->parameters(['salespeople' => 'salesperson']);
});
