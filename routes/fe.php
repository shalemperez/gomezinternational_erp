<?php

use App\Http\Controllers\Fe\ReceptionController;
use Illuminate\Support\Facades\Route;

// DGII receiver web services (RES §9): public, no session or CSRF; paths are case-insensitive.
// The wildcard names differ: routes sharing one URI pattern replace each other in the route collection.
Route::post('{path}', [ReceptionController::class, 'receive'])->where('path', '(?i)fe/recepcion/api/ecf')->name('fe.reception');
Route::post('{approvalPath}', [ReceptionController::class, 'commercialApproval'])->where('approvalPath', '(?i)fe/aprobacioncomercial/api/ecf')->name('fe.approval');
