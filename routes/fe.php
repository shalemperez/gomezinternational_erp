<?php

use App\Http\Controllers\Fe\AlanubeWebhookController;
use App\Http\Controllers\Fe\ReceptionController;
use App\Http\Middleware\LimitFeRequestSize;
use Illuminate\Support\Facades\Route;

// DGII receiver web services (RES §9): public, no session or CSRF; paths are case-insensitive.
// The wildcard names differ: routes sharing one URI pattern replace each other in the route collection.
// Every /fe route is throttled and size-capped before any signature or XSD work (GC-26).
Route::middleware(['throttle:fe', LimitFeRequestSize::class])->group(function () {
    Route::post('{path}', [ReceptionController::class, 'receive'])->where('path', '(?i)fe/recepcion/api/ecf')->name('fe.reception');
    Route::post('{approvalPath}', [ReceptionController::class, 'commercialApproval'])->where('approvalPath', '(?i)fe/aprobacioncomercial/api/ecf')->name('fe.approval');
    // Alanube webhook (RES §12): authenticated by the x-api-key header. Its wildcard name differs from {path} for the same reason.
    Route::post('{alanubePath}', [AlanubeWebhookController::class, 'handle'])->where('alanubePath', '(?i)fe/alanube/webhook')->name('fe.alanube.webhook');
});
