<?php

namespace Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Security\Models\UserSession;
use Modules\Security\Services\SessionService;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    /**
     * Sesiones por dispositivo. Por defecto solo las activas (status=all para todas).
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = UserSession::query()
            ->with(['user:id,username,first_name,last_name,company_id', 'user.company:id,name', 'revoker:id,username'])
            ->when($request->input('status', 'active') === 'active', fn ($query) => $query->active())
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('platform'), fn ($query) => $query->where('platform', $request->string('platform')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q
                    ->where('device_name', 'like', $term)
                    ->orWhere('device_id', 'like', $term)
                    ->orWhere('ip_address', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', $term)->orWhere('first_name', 'like', $term)->orWhere('last_name', 'like', $term)));
            })
            ->latest('last_used_at')
            ->paginate($this->perPage($request));

        $currentSessionId = auth('api')->payload()->get('sid');

        return $this->paginated($sessions, fn (UserSession $session): array => [
            'id' => $session->id,
            'user' => $session->user ? [
                'id' => $session->user->id,
                'username' => $session->user->username,
                'full_name' => $session->user->full_name,
                'company' => $session->user->company?->name,
            ] : null,
            'platform' => $session->platform->value,
            'device_id' => $session->device_id,
            'device_name' => $session->device_name,
            'ip_address' => $session->ip_address,
            'last_used_at' => $session->last_used_at?->toISOString(),
            'expires_at' => $session->expires_at->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
            'is_active' => $session->isActive(),
            'is_current' => $session->id === $currentSessionId,
            'revoked_at' => $session->revoked_at?->toISOString(),
            'revoke_reason' => $session->revoke_reason,
            'revoked_by' => $session->revoker?->username,
        ]);
    }

    /**
     * Cierra una sesión específica (ej. un celular perdido).
     */
    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        $this->sessions->revoke($session, UserSession::REVOKED_ADMIN, $request->user());

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Cierra todas las sesiones de un usuario, o solo las de un dispositivo.
     */
    public function revokeForUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['device_id' => ['nullable', 'string', 'max:100']]);
        $deviceId = $data['device_id'] ?? null;

        $count = $this->sessions->revokeForUser(
            $user,
            $deviceId ? UserSession::REVOKED_DEVICE : UserSession::REVOKED_ADMIN,
            $request->user(),
            $deviceId,
        );

        return response()->json([
            'message' => "Se cerraron {$count} sesión(es) de {$user->username}.",
            'data' => ['revoked' => $count],
        ]);
    }
}
