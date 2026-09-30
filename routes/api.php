<?php

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\EnsurePermission as P;
use Illuminate\Support\Facades\Route;

Route::middleware('secured')->prefix('v1')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware(P::using('DASH:VER'))
        ->name('dashboard');
});
