<?php

use App\Http\Middleware\EnsurePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Security\Http\Middleware\EnsureActiveSession;
use Modules\Security\Http\Middleware\EnsurePasswordChanged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            EnsurePermission::ALIAS => EnsurePermission::class,
            'session.active' => EnsureActiveSession::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        // En Dokploy la API está detrás del proxy (Traefik): así se registra la IP real del usuario
        // en accesos y sesiones, y el límite de intentos de login (por IP) no se comparte entre todos.
        $middleware->trustProxies(at: '*');

        // Es una API: nunca redirigir a una pantalla de login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        // Rutas protegidas: JWT válido + sesión vigente + contraseña ya cambiada.
        $middleware->group('secured', [
            'auth:api',
            'session.active',
            'password.changed',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado.', 'code' => 'UNAUTHENTICATED'], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'El registro solicitado no existe.'], 404);
            }
        });
    })->create();
