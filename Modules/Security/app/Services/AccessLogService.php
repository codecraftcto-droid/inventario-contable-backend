<?php

namespace Modules\Security\Services;

use App\Enums\AccessEvent;
use App\Enums\Platform;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Security\Models\AccessLog;
use Modules\Security\Models\UserSession;

/**
 * Registra los eventos de la bitácora de accesos (access_logs).
 */
class AccessLogService
{
    public function record(
        Request $request,
        AccessEvent $event,
        ?User $user = null,
        ?string $login = null,
        ?UserSession $session = null,
        ?Platform $platform = null,
        ?string $deviceId = null,
        ?string $detail = null,
    ): AccessLog {
        return AccessLog::create([
            'user_id' => $user?->id,
            'user_session_id' => $session?->id,
            'login' => $login ?? $user?->username,
            'event' => $event,
            'platform' => $platform ?? $session?->platform,
            'device_id' => $deviceId ?? $session?->device_id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
            'detail' => $detail,
        ]);
    }
}
