<?php

use App\Http\Middleware\EnsurePermission as P;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\ActionController;
use Modules\Core\Http\Controllers\ModuleController;
use Modules\Core\Http\Controllers\PermissionController;

Route::middleware('secured')->prefix('v1/core')->name('core.')->group(function (): void {
    // --- Módulos (SEG_MOD) ---
    Route::get('modules', [ModuleController::class, 'index'])->middleware(P::using('SEG_MOD:VER', 'SEG_PER:VER'))->name('modules.index');
    Route::post('modules', [ModuleController::class, 'store'])->middleware(P::using('SEG_MOD:CREAR'))->name('modules.store');
    Route::get('modules/{module}', [ModuleController::class, 'show'])->middleware(P::using('SEG_MOD:VER'))->name('modules.show');
    Route::put('modules/{module}', [ModuleController::class, 'update'])->middleware(P::using('SEG_MOD:EDITAR'))->name('modules.update');
    Route::delete('modules/{module}', [ModuleController::class, 'destroy'])->middleware(P::using('SEG_MOD:ELIMINAR'))->name('modules.destroy');

    // --- Permisos y catálogo de acciones (SEG_PER) ---
    Route::get('permissions', [PermissionController::class, 'index'])->middleware(P::using('SEG_PER:VER', 'SEG_ROL:VER'))->name('permissions.index');
    Route::put('modules/{module}/actions', [PermissionController::class, 'syncModuleActions'])->middleware(P::using('SEG_PER:EDITAR'))->name('modules.actions.sync');

    Route::get('actions', [ActionController::class, 'index'])->middleware(P::using('SEG_PER:VER', 'SEG_MOD:VER', 'SEG_ROL:VER'))->name('actions.index');
    Route::post('actions', [ActionController::class, 'store'])->middleware(P::using('SEG_PER:CREAR'))->name('actions.store');
    Route::put('actions/{action}', [ActionController::class, 'update'])->middleware(P::using('SEG_PER:EDITAR'))->name('actions.update');
    Route::delete('actions/{action}', [ActionController::class, 'destroy'])->middleware(P::using('SEG_PER:ELIMINAR'))->name('actions.destroy');
});
