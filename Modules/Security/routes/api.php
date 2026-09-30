<?php

use App\Http\Middleware\EnsurePermission as P;
use Illuminate\Support\Facades\Route;
use Modules\Security\Http\Controllers\AccessLogController;
use Modules\Security\Http\Controllers\AuthController;
use Modules\Security\Http\Controllers\RoleController;
use Modules\Security\Http\Controllers\SessionController;
use Modules\Security\Http\Controllers\UserController;

Route::prefix('v1/security')->name('security.')->group(function (): void {
    // --- Autenticación ---
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
        Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1')->name('refresh');

        // Permitidas aunque falte el cambio de contraseña obligatorio.
        Route::middleware(['auth:api', 'session.active'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1')->name('change-password');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

    Route::middleware('secured')->group(function (): void {
        // --- Usuarios (SEG_USU) ---
        Route::get('users', [UserController::class, 'index'])->middleware(P::using('SEG_USU:VER'))->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware(P::using('SEG_USU:CREAR'))->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware(P::using('SEG_USU:VER'))->name('users.show');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware(P::using('SEG_USU:EDITAR'))->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware(P::using('SEG_USU:ELIMINAR'))->name('users.destroy');
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware(P::using('SEG_USU:EDITAR'))->name('users.status');
        Route::post('users/{user}/unlock', [UserController::class, 'unlock'])->middleware(P::using('SEG_USU:EDITAR'))->name('users.unlock');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware(P::using('SEG_USU:EDITAR'))->name('users.reset-password');
        Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->middleware(P::using('SEG_USU:EDITAR'))->name('users.roles');

        // --- Roles (SEG_ROL) ---
        Route::get('roles', [RoleController::class, 'index'])->middleware(P::using('SEG_ROL:VER', 'SEG_USU:VER'))->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware(P::using('SEG_ROL:CREAR'))->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])->middleware(P::using('SEG_ROL:VER'))->name('roles.show');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware(P::using('SEG_ROL:EDITAR'))->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware(P::using('SEG_ROL:ELIMINAR'))->name('roles.destroy');
        Route::get('roles/{role}/permissions', [RoleController::class, 'permissions'])->middleware(P::using('SEG_ROL:VER'))->name('roles.permissions');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware(P::using('SEG_ROL:EDITAR'))->name('roles.permissions.sync');

        // --- Sesiones y log de accesos (SEG_SES) ---
        Route::get('sessions', [SessionController::class, 'index'])->middleware(P::using('SEG_SES:VER'))->name('sessions.index');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->middleware(P::using('SEG_SES:ELIMINAR'))->name('sessions.destroy');
        Route::post('users/{user}/sessions/revoke', [SessionController::class, 'revokeForUser'])->middleware(P::using('SEG_SES:ELIMINAR'))->name('users.sessions.revoke');
        Route::get('access-logs', AccessLogController::class)->middleware(P::using('SEG_SES:VER'))->name('access-logs.index');
    });
});
