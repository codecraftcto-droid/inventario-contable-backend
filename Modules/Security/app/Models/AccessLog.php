<?php

namespace Modules\Security\Models;

use App\Enums\AccessEvent;
use App\Enums\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de accesos (solo inserción).
 */
class AccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_session_id',
        'login',
        'event',
        'platform',
        'device_id',
        'ip_address',
        'user_agent',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'event' => AccessEvent::class,
            'platform' => Platform::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'user_session_id');
    }
}
