<?php

namespace Modules\Security\Services;

use App\Enums\AccessEvent;
use App\Enums\Platform;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Services\ModuleTreeService;
use Modules\Security\Exceptions\AuthFailedException;
use Modules\Security\Models\UserSession;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AccessLogService $accessLog,
        private readonly ModuleTreeService $moduleTree,
    ) {}

    /**
     * Inicia sesión con número de documento, correo o usuario. Aplica el bloqueo temporal
     * tras N intentos fallidos y registra cada intento en la bitácora.
     *
     * @throws AuthFailedException
     */
    public function login(Request $request, array $credentials): array
    {
        $login = trim($credentials['login']);
        $platform = Platform::from($credentials['platform'] ?? Platform::WEB->value);
        $deviceId = $credentials['device_id'] ?? null;

        $user = User::query()
            ->with('company')
            ->where(fn ($query) => $query
                ->where('document_number', mb_strtoupper($login))
                ->orWhere('email', mb_strtolower($login))
                ->orWhere('username', $login))
            ->first();

        $fail = function (AccessEvent $event, string $detail, string $message, int $status = 401) use ($request, $user, $login, $platform, $deviceId): never {
            $this->accessLog->record($request, $event, $user, $login, platform: $platform, deviceId: $deviceId, detail: $detail);

            throw new AuthFailedException($message, $status);
        };

        $invalidCredentials = 'Usuario o contraseña incorrectos.';

        if (! $user) {
            $fail(AccessEvent::LOGIN_FALLIDO, 'Usuario no existe', $invalidCredentials);
        }

        if ($user->isLocked()) {
            $fail(AccessEvent::LOGIN_FALLIDO, 'Usuario bloqueado', $this->lockedMessage($user), 423);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $this->registerFailedAttempt($request, $user, $login, $platform, $deviceId);

            if ($user->isLocked()) {
                throw new AuthFailedException($this->lockedMessage($user), 423);
            }

            throw new AuthFailedException($invalidCredentials, 401);
        }

        if (! $user->is_active) {
            $fail(AccessEvent::LOGIN_FALLIDO, 'Usuario inactivo', 'Tu cuenta está desactivada. Contacta al administrador.', 403);
        }

        if ($user->isClientUser() && ! $user->company?->is_active) {
            $fail(AccessEvent::LOGIN_FALLIDO, 'Empresa inactiva', 'La empresa a la que perteneces está desactivada. Contacta al administrador.', 403);
        }

        return DB::transaction(function () use ($request, $user, $platform, $deviceId, $credentials): array {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
            ])->save();

            // Un dispositivo móvil mantiene una sola sesión: un nuevo login la reemplaza.
            if ($deviceId) {
                $this->sessions->revokeForUser($user, UserSession::REVOKED_NEW_LOGIN, deviceId: $deviceId);
            }

            [$session, $refreshToken] = $this->sessions->create($user, $platform, $request, $deviceId, $credentials['device_name'] ?? null);

            $this->accessLog->record($request, AccessEvent::LOGIN_OK, $user, session: $session);

            return $this->tokenPayload($user, $session, $refreshToken);
        });
    }

    /**
     * Renueva el access token con el refresh token (que se rota en cada uso).
     *
     * @throws AuthFailedException
     */
    public function refresh(Request $request, string $refreshToken, ?string $deviceId = null): array
    {
        $session = $this->sessions->findActiveByToken($refreshToken);

        if (! $session) {
            throw new AuthFailedException('La sesión expiró. Inicia sesión nuevamente.', 401);
        }

        $user = $session->user;

        // Un refresh token de un celular usado desde otro dispositivo se considera robado.
        $deviceMismatch = $session->device_id !== null && $deviceId !== null && $session->device_id !== $deviceId;

        if ($deviceMismatch || ! $user || ! $user->is_active || $user->isLocked()) {
            $this->sessions->revoke($session, UserSession::REVOKED_INVALID_REFRESH);

            throw new AuthFailedException('La sesión expiró. Inicia sesión nuevamente.', 401);
        }

        $newRefreshToken = $this->sessions->rotate($session, $request);

        return $this->tokenPayload($user, $session, $newRefreshToken);
    }

    public function logout(Request $request, User $user): void
    {
        $session = $this->currentSession();

        if ($session) {
            $this->sessions->revoke($session, UserSession::REVOKED_LOGOUT, $user);
        }

        $this->accessLog->record($request, AccessEvent::LOGOUT, $user, session: $session);

        $this->guard()->invalidate();
    }

    /**
     * Cambia la contraseña del propio usuario y cierra sus otras sesiones.
     */
    public function changePassword(Request $request, User $user, string $newPassword): void
    {
        DB::transaction(function () use ($request, $user, $newPassword): void {
            $user->forceFill([
                'password' => $newPassword,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            $session = $this->currentSession();
            $this->sessions->revokeForUser($user, UserSession::REVOKED_PASSWORD_CHANGE, $user, exceptSessionId: $session?->id);

            $this->accessLog->record($request, AccessEvent::CAMBIO_CLAVE, $user, session: $session);
        });
    }

    /**
     * Datos de la sesión actual: usuario, permisos MODULO:ACCION y menú de la plataforma.
     */
    public function profile(User $user, Platform $platform): array
    {
        $user->loadMissing(['company', 'roles']);

        return [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'document_number' => $user->document_number,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => $user->full_name,
                'phone' => $user->phone,
                'user_type' => $user->user_type->value,
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'code' => $user->company->code,
                    'name' => $user->company->name,
                ] : null,
                'is_root_admin' => $user->isRootAdmin(),
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toISOString(),
            ],
            'roles' => $user->roles->where('is_active', true)->pluck('name')->values()->all(),
            'permissions' => $user->permissionNames(),
            'platform' => $platform->value,
            'menu' => $this->moduleTree->menuFor($user, $platform),
        ];
    }

    /**
     * Sesión (refresh token) asociada al access token de la petición actual.
     */
    public function currentSession(): ?UserSession
    {
        $sessionId = $this->guard()->check() ? $this->guard()->payload()->get('sid') : null;

        return $sessionId ? UserSession::find($sessionId) : null;
    }

    public function currentPlatform(): Platform
    {
        $value = $this->guard()->check() ? $this->guard()->payload()->get('plt') : null;

        return Platform::tryFrom((string) $value) ?? Platform::WEB;
    }

    private function tokenPayload(User $user, UserSession $session, string $refreshToken): array
    {
        $accessToken = $this->guard()
            ->claims(['sid' => $session->id, 'plt' => $session->platform->value])
            ->login($user);

        $ttlMinutes = $this->guard()->factory()->getTTL();

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
            'refresh_token' => $refreshToken,
            'refresh_expires_at' => $session->expires_at->toISOString(),
            'session_id' => $session->id,
            ...$this->profile($user, $session->platform),
        ];
    }

    private function registerFailedAttempt(Request $request, User $user, string $login, Platform $platform, ?string $deviceId): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $maxAttempts = max(1, (int) config('security.max_login_attempts'));

        if ($attempts >= $maxAttempts) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes((int) config('security.lockout_minutes')),
            ])->save();

            $this->accessLog->record($request, AccessEvent::LOGIN_FALLIDO, $user, $login, platform: $platform, deviceId: $deviceId, detail: 'Contraseña incorrecta');
            $this->accessLog->record($request, AccessEvent::BLOQUEO, $user, $login, platform: $platform, deviceId: $deviceId, detail: "Bloqueado tras {$maxAttempts} intentos fallidos");

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();

        $this->accessLog->record($request, AccessEvent::LOGIN_FALLIDO, $user, $login, platform: $platform, deviceId: $deviceId, detail: "Contraseña incorrecta (intento {$attempts} de {$maxAttempts})");
    }

    private function lockedMessage(User $user): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

        return "Usuario bloqueado temporalmente por intentos fallidos. Intenta nuevamente en {$minutes} minuto(s) o contacta al administrador.";
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return Auth::guard('api');
    }
}
