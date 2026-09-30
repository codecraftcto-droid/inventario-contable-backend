<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un permiso MODULO:ACCION en la ruta. Uso:
 *   ->middleware(EnsurePermission::using('INV:EDITAR'))
 *   ->middleware(EnsurePermission::using('INV:VER', 'INV:EXPORTAR'))   // cualquiera de ellos
 *
 * Si el permiso no está asignado (o no existe) se deniega: nunca se abre por defecto.
 */
class EnsurePermission
{
    public const ALIAS = 'requires.permission';

    public static function using(string ...$permissions): string
    {
        return self::ALIAS.':'.implode('|', $permissions);
    }

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        $required = explode('|', $permissions);

        $allowed = $user && ($user->isRootAdmin() || array_intersect($required, $user->permissionNames()) !== []);

        if (! $allowed) {
            return response()->json([
                'message' => 'No tienes permiso para realizar esta acción.',
                'code' => 'FORBIDDEN',
                'required' => $required,
            ], 403);
        }

        return $next($request);
    }
}
