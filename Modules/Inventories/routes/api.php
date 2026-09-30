<?php

use App\Http\Middleware\EnsurePermission as P;
use Illuminate\Support\Facades\Route;
use Modules\Inventories\Http\Controllers\AssetController;
use Modules\Inventories\Http\Controllers\AssignmentController;
use Modules\Inventories\Http\Controllers\InventoryController;
use Modules\Inventories\Http\Controllers\PocketController;
use Modules\Inventories\Http\Controllers\ScanController;
use Modules\Inventories\Http\Controllers\ScanPhotoController;
use Modules\Inventories\Http\Controllers\TakeController;

Route::middleware('secured')->prefix('v1/inventories')->name('inventories.')->group(function (): void {
    // --- Listado de inventarios (INV) ---
    Route::get('/', [InventoryController::class, 'index'])->middleware(P::using('INV:VER'))->name('index');
    Route::post('/', [InventoryController::class, 'store'])->middleware(P::using('INV:CREAR'))->name('store');
    Route::get('/accounting-base-template', [AssetController::class, 'template'])->middleware(P::using('INV_BASE:IMPORTAR'))->name('accounting-base.template');
    Route::get('/{inventory}', [InventoryController::class, 'show'])->middleware(P::using('INV:VER'))->name('show');
    Route::put('/{inventory}', [InventoryController::class, 'update'])->middleware(P::using('INV:EDITAR'))->name('update');
    Route::delete('/{inventory}', [InventoryController::class, 'destroy'])->middleware(P::using('INV:ELIMINAR'))->name('destroy');
    // Botones del inventario (APROBAR = controlar la toma): iniciar, pausar, reanudar, finalizar
    Route::post('/{inventory}/start', [InventoryController::class, 'start'])->middleware(P::using('INV:APROBAR'))->name('start');
    Route::post('/{inventory}/pause', [InventoryController::class, 'pause'])->middleware(P::using('INV:APROBAR'))->name('pause');
    Route::post('/{inventory}/resume', [InventoryController::class, 'resume'])->middleware(P::using('INV:APROBAR'))->name('resume');
    Route::post('/{inventory}/close', [InventoryController::class, 'close'])->middleware(P::using('INV:APROBAR'))->name('close');
    Route::get('/{inventory}/history', [InventoryController::class, 'history'])->middleware(P::using('INV:VER'))->name('history');

    // --- Opción "Base contable" de cada inventario (INV_BASE) ---
    Route::scopeBindings()->prefix('/{inventory}/accounting-base')->name('accounting-base.')->group(function (): void {
        Route::get('/', [AssetController::class, 'index'])->middleware(P::using('INV_BASE:VER'))->name('index');
        Route::post('/import', [AssetController::class, 'import'])->middleware(P::using('INV_BASE:IMPORTAR'))->name('import');
        Route::get('/export', [AssetController::class, 'export'])->middleware(P::using('INV_BASE:EXPORTAR'))->name('export');
        Route::post('/', [AssetController::class, 'store'])->middleware(P::using('INV_BASE:CREAR'))->name('store');
        Route::put('/{asset}', [AssetController::class, 'update'])->middleware(P::using('INV_BASE:EDITAR'))->name('update');
        Route::delete('/{asset}', [AssetController::class, 'destroy'])->middleware(P::using('INV_BASE:ELIMINAR'))->name('destroy');
    });

    // --- Opción "Asignar inventariadores" (INV_ASIG) ---
    Route::prefix('/{inventory}/assignees')->name('assignees.')->group(function (): void {
        Route::get('/', [AssignmentController::class, 'index'])->middleware(P::using('INV_ASIG:VER'))->name('index');
        Route::get('/candidates', [AssignmentController::class, 'candidates'])->middleware(P::using('INV_ASIG:EDITAR'))->name('candidates');
        Route::post('/', [AssignmentController::class, 'store'])->middleware(P::using('INV_ASIG:EDITAR'))->name('store');
        Route::delete('/{user}', [AssignmentController::class, 'destroy'])->middleware(P::using('INV_ASIG:EDITAR'))->name('destroy');
    });

    // --- Opción "Pockets" (INV_POCK): zonas / tandas de conteo asignadas a inventariadores ---
    Route::scopeBindings()->prefix('/{inventory}/pockets')->name('pockets.')->group(function (): void {
        Route::get('/', [PocketController::class, 'index'])->middleware(P::using('INV_POCK:VER'))->name('index');
        Route::get('/candidates', [PocketController::class, 'candidates'])->middleware(P::using('INV_POCK:EDITAR', 'INV_POCK:CREAR'))->name('candidates');
        Route::post('/', [PocketController::class, 'store'])->middleware(P::using('INV_POCK:CREAR'))->name('store');
        Route::post('/bulk', [PocketController::class, 'bulk'])->middleware(P::using('INV_POCK:CREAR'))->name('bulk');
        Route::put('/{pocket}', [PocketController::class, 'update'])->middleware(P::using('INV_POCK:EDITAR'))->name('update');
        Route::delete('/{pocket}', [PocketController::class, 'destroy'])->middleware(P::using('INV_POCK:ELIMINAR'))->name('destroy');
        Route::post('/{pocket}/reopen', [PocketController::class, 'reopen'])->middleware(P::using('INV_POCK:EDITAR'))->name('reopen');
        // Teléfono
        Route::get('/mine', [PocketController::class, 'mine'])->middleware(P::using('INV_TOMA:VER'))->name('mine')->withoutScopedBindings();
        Route::post('/{pocket}/finish', [PocketController::class, 'finish'])->middleware(P::using('INV_TOMA:ESCANEAR', 'INV_POCK:EDITAR'))->name('finish');
    });

    // --- Opción "Toma de inventario" (INV_TOMA): app móvil y web ---
    Route::prefix('/{inventory}')->name('scans.')->group(function (): void {
        Route::get('/scan-base', [ScanController::class, 'base'])->middleware(P::using('INV_TOMA:VER'))->name('base');
        Route::get('/scans', [ScanController::class, 'index'])->middleware(P::using('INV_TOMA:VER'))->name('index');
        Route::post('/scans/batch', [ScanController::class, 'store'])->middleware(P::using('INV_TOMA:SINCRONIZAR', 'INV_TOMA:ESCANEAR'))->name('store');
        Route::post('/scans/delete', [ScanController::class, 'destroyBatch'])->middleware(P::using('INV_TOMA:SINCRONIZAR', 'INV_TOMA:ESCANEAR'))->name('destroy-batch');
        Route::get('/sites', [ScanController::class, 'sites'])->middleware(P::using('INV_TOMA:VER'))->name('sites');

        // Fotos de los registros (el registro se identifica por el uuid que generó el equipo)
        Route::post('/scans/{scan}/photos', [ScanPhotoController::class, 'store'])->middleware(P::using('INV_TOMA:SINCRONIZAR', 'INV_TOMA:ESCANEAR'))->name('photos.store');
        Route::delete('/scans/{scan}/photos/{photo}', [ScanPhotoController::class, 'destroy'])->middleware(P::using('INV_TOMA:SINCRONIZAR', 'INV_TOMA:ESCANEAR'))->name('photos.destroy');
        Route::get('/photos/{photo}', [ScanPhotoController::class, 'show'])->middleware(P::using('INV_TOMA:VER'))->name('photos.show');
    });

    // --- Toma de inventario en la web: avance, listados paginados y resultado ---
    Route::scopeBindings()->prefix('/{inventory}/take')->name('take.')->group(function (): void {
        Route::get('/summary', [TakeController::class, 'summary'])->middleware(P::using('INV_TOMA:VER'))->name('summary');
        Route::get('/scans', [TakeController::class, 'scans'])->middleware(P::using('INV_TOMA:VER'))->name('scans');
        Route::get('/missing', [TakeController::class, 'missing'])->middleware(P::using('INV_TOMA:VER'))->name('missing');
        Route::get('/reconciliation', [TakeController::class, 'reconciliation'])->middleware(P::using('INV_TOMA:VER'))->name('reconciliation');
        Route::get('/export', [TakeController::class, 'export'])->middleware(P::using('INV_TOMA:EXPORTAR'))->name('export');
        Route::delete('/scans/{scan}', [TakeController::class, 'destroy'])->middleware(P::using('INV_TOMA:ELIMINAR'))->name('scans.destroy');
    });
});
