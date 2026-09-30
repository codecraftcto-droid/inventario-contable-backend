<?php

namespace Modules\Security\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Security\Models\UserSession;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Además de un JWT válido, exige que su sesión (claim "sid") siga vigente y que el
 * usuario siga activo. Así, revocar una sesión (ej. celular perdido) o desactivar
 * al usuario corta el acceso de inmediato, sin esperar a que venza el token.
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var JWTGuard $guard */
        $guard = Auth::guard('api');
        $user = $guard->user();
        $sessionId = $user ? $guard->payload()->get('sid') : null;

        $sessionIsActive = $sessionId && UserSession::query()
            ->active()
            ->whereKey($sessionId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $sessionIsActive || ! $user->is_active || $user->isLocked()) {
            return response()->json([
                'message' => 'Tu sesión fue cerrada o expiró. Inicia sesión nuevamente.',
                'code' => 'SESSION_REVOKED',
            ], 401);
        }

        return $next($request);
    }
}
