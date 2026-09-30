<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Action;
use Modules\Core\Models\Module;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permiso = módulo + acción. Su "name" es MODULO:ACCION (ej. INV_TOM:ESCANEAR).
 */
class Permission extends SpatiePermission
{
    use TracksAuthor;

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public static function nameFor(string $moduleCode, string $actionCode): string
    {
        return "{$moduleCode}:{$actionCode}";
    }
}
