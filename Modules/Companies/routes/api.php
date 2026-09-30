<?php

use App\Http\Middleware\EnsurePermission as P;
use Illuminate\Support\Facades\Route;
use Modules\Companies\Http\Controllers\CompanyController;
use Modules\Companies\Http\Controllers\SiteController;

Route::middleware('secured')->prefix('v1/companies')->name('companies.')->group(function (): void {
    // El listado también lo usan Usuarios (asignar empresa) e Inventarios (filtrar/crear por empresa).
    Route::get('/', [CompanyController::class, 'index'])->middleware(P::using('EMP:VER', 'SEG_USU:VER', 'INV:CREAR'))->name('index');
    Route::post('/', [CompanyController::class, 'store'])->middleware(P::using('EMP:CREAR'))->name('store');
    Route::get('/{company}', [CompanyController::class, 'show'])->middleware(P::using('EMP:VER'))->name('show');
    Route::put('/{company}', [CompanyController::class, 'update'])->middleware(P::using('EMP:EDITAR'))->name('update');
    Route::delete('/{company}', [CompanyController::class, 'destroy'])->middleware(P::using('EMP:ELIMINAR'))->name('destroy');

    // --- Opción "Sedes" de cada empresa (EMP_SEDE) ---
    Route::scopeBindings()->prefix('/{company}/sites')->name('sites.')->group(function (): void {
        Route::get('/', [SiteController::class, 'index'])->middleware(P::using('EMP_SEDE:VER'))->name('index');
        Route::post('/', [SiteController::class, 'store'])->middleware(P::using('EMP_SEDE:CREAR'))->name('store');
        Route::put('/{site}', [SiteController::class, 'update'])->middleware(P::using('EMP_SEDE:EDITAR'))->name('update');
        Route::delete('/{site}', [SiteController::class, 'destroy'])->middleware(P::using('EMP_SEDE:ELIMINAR'))->name('destroy');
    });
});
