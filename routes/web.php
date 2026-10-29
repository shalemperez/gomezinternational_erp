<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

// Placeholder until Task 6 replaces it with the Google login page.
Route::get('login', fn () => Inertia::render('auth/login'))->middleware('guest')->name('login');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});
