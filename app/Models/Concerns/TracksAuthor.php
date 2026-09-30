<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Llena created_by / updated_by con el usuario autenticado (columnas creadas
 * con la macro $table->auditColumns()). En seeders o consola quedan en null.
 */
trait TracksAuthor
{
    public static function bootTracksAuthor(): void
    {
        static::creating(function (Model $model): void {
            $userId = Auth::id();

            $model->created_by ??= $userId;
            $model->updated_by ??= $userId;
        });

        static::updating(function (Model $model): void {
            if ($userId = Auth::id()) {
                $model->updated_by = $userId;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
