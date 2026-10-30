<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\NcfSequenceController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DataImport\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::post('users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('users.impersonate');
    Route::delete('impersonate', [ImpersonateController::class, 'stop'])->name('impersonate.stop');
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::put('roles/{role:name}', [RoleController::class, 'update'])->name('roles.update');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::resource('ncf-sequences', NcfSequenceController::class)->except(['show', 'destroy'])->parameters(['ncf-sequences' => 'ncfSequence']);
    Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::put('email-templates/{emailTemplate:key}', [EmailTemplateController::class, 'update'])->name('email-templates.update');
    Route::get('activity-log/export', [ActivityLogController::class, 'export'])->name('activity-log.export');
    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
    Route::middleware('permission:catalog.clients.import|catalog.suppliers.import|catalog.products.import|catalog.price-lists.import|inventory.initial-stock.import|sales.historical-invoices.import')->group(function () {
        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('imports/{type}/template', [ImportController::class, 'template'])->name('imports.template');
        Route::post('imports/{type}', [ImportController::class, 'preview'])->name('imports.preview');
        Route::get('imports/batches/{batch}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('imports/batches/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
        Route::delete('imports/batches/{batch}', [ImportController::class, 'cancel'])->name('imports.cancel');
    });
});
