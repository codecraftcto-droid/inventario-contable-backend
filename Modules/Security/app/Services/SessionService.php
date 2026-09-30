<?php

namespace Modules\Security\Services;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Security\Models\UserSession;

/**
 * Sesiones = refresh tokens por dispositivo. El token en claro solo se devuelve
 * al cliente una vez; en BD queda su hash SHA-256.
 */
class SessionService
{
    /**
     * Crea una sesión y devuelve [sesión, refresh token en claro].
     *
     * @return array{0: UserSession, 1: string}
     */
    public function create(User $user, Platform $platform, Request $request, ?string $deviceId, ?string $deviceName): array
    {
        $plainToken = $this->newToken();

        $session = UserSession::create([
            'user_id' => $user->id,
            'refresh_token_hash' => UserSession::hashToken($plainToken),
            'platform' => $platform,
            'device_id' => $deviceId,
            'device_name' => $deviceName ?? $this->guessDeviceName($request),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
            'last_used_at' => now(),
            'expires_at' => $this->expiresAt($platform),
        ]);

        return [$session, $plainToken];
    }

    /**
     * Busca una sesión vigente por su refresh token en claro.
     */
    public function findActiveByToken(string $plainToken): ?UserSession
    {
        return UserSession::query()
            ->active()
            ->where('refresh_token_hash', UserSession::hashToken($plainToken))
            ->first();
    }

    /**
     * Rota el refresh token (el anterior deja de servir) y extiende la vigencia.
     */
    public function rotate(UserSession $session, Request $request): string
    {
        $plainToken = $this->newToken();

        $session->forceFill([
            'refresh_token_hash' => UserSession::hashToken($plainToken),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => $this->expiresAt($session->platform),
        ])->save();

        return $plainToken;
    }

    public function revoke(UserSession $session, string $reason, ?User $revokedBy = null): void
    {
        if ($session->revoked_at !== null) {
            return;
        }

        $session->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy?->id,
            'revoke_reason' => $reason,
        ])->save();
    }

    /**
     * Revoca las sesiones activas de un usuario (opcionalmente solo de un dispositivo
     * o dejando una sesión viva). Devuelve cuántas se revocaron.
     */
    public function revokeForUser(User $user, string $reason, ?User $revokedBy = null, ?string $deviceId = null, ?int $exceptSessionId = null): int
    {
        return UserSession::query()
            ->active()
            ->where('user_id', $user->id)
            ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId))
            ->when($exceptSessionId, fn ($query) => $query->whereKeyNot($exceptSessionId))
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $revokedBy?->id,
                'revoke_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    private function expiresAt(Platform $platform): Carbon
    {
        return now()->addDays(config("security.refresh_ttl_days.{$platform->value}", 1));
    }

    private function newToken(): string
    {
        return Str::random(80);
    }

    private function guessDeviceName(Request $request): ?string
    {
        $agent = (string) $request->userAgent();

        return match (true) {
            $agent === '' => null,
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => substr($agent, 0, 150),
        };
    }
}
