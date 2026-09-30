<?php

namespace Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Security\Http\Requests\ChangePasswordRequest;
use Modules\Security\Http\Requests\LoginRequest;
use Modules\Security\Services\AuthService;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Devuelve tokens + usuario + permisos (MODULO:ACCION) + menú de la plataforma.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Sesión iniciada correctamente.',
            'data' => $this->auth->login($request, $request->validated()),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'message' => 'Sesión renovada correctamente.',
            'data' => $this->auth->refresh($request, $data['refresh_token'], $data['device_id'] ?? null),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->auth->profile($request->user(), $this->auth->currentPlatform()),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->auth->changePassword($request, $user, $request->validated('password'));

        return response()->json([
            'message' => 'Contraseña actualizada correctamente. Se cerraron tus otras sesiones.',
            'data' => $this->auth->profile($user->fresh(), $this->auth->currentPlatform()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request, $request->user());

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
}
