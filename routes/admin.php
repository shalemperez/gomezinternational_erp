<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::post('users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('users.impersonate');
    Route::delete('impersonate', [ImpersonateController::class, 'stop'])->name('impersonate.stop');
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::put('roles/{role:name}', [RoleController::class, 'update'])->name('roles.update');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('activity-log/export', [ActivityLogController::class, 'export'])->name('activity-log.export');
    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
});
