<?php

namespace Modules\Security\Models;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión = refresh token emitido a un dispositivo/navegador.
 */
class UserSession extends Model
{
    // Motivos de revocación (columna revoke_reason)
    public const REVOKED_LOGOUT = 'LOGOUT';

    public const REVOKED_ADMIN = 'ADMIN';

    public const REVOKED_DEVICE = 'DISPOSITIVO';

    public const REVOKED_PASSWORD_CHANGE = 'CAMBIO_CLAVE';

    public const REVOKED_PASSWORD_RESET = 'RESETEO_CLAVE';

    public const REVOKED_NEW_LOGIN = 'NUEVO_LOGIN';

    public const REVOKED_USER_DISABLED = 'USUARIO_DESACTIVADO';

    public const REVOKED_INVALID_REFRESH = 'REFRESH_INVALIDO';

    protected $fillable = [
        'user_id',
        'refresh_token_hash',
        'platform',
        'device_id',
        'device_name',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $hidden = [
        'refresh_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Sesiones vigentes: no revocadas y no expiradas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
