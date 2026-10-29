<?php

use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::post('users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('users.impersonate');
    Route::delete('impersonate', [ImpersonateController::class, 'stop'])->name('impersonate.stop');
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::put('roles/{role:name}', [RoleController::class, 'update'])->name('roles.update');
});
