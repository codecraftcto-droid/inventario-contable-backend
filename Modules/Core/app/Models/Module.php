<?php

namespace Modules\Core\Models;

use App\Enums\Platform;
use App\Models\Concerns\TracksAuthor;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    use TracksAuthor;

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'description',
        'icon',
        'path',
        'sort_order',
        'platform',
        'is_menu',
        'is_active',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'platform' => Platform::class,
            'is_menu' => 'boolean',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    /**
     * Acciones habilitadas para este módulo (a través de sus permisos).
     */
    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(Action::class, 'permissions')->orderBy('sort_order');
    }

    /**
     * IDs de los módulos de administración interna (config security.internal_only_modules)
     * y de todos sus submódulos. Sus permisos nunca se dan a usuarios de empresas cliente.
     *
     * @return int[]
     */
    public static function internalOnlyIds(): array
    {
        $modules = static::query()->get(['id', 'parent_id', 'code']);
        $ids = $modules->whereIn('code', config('security.internal_only_modules', []))->pluck('id')->all();

        do {
            $children = $modules->whereIn('parent_id', $ids)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = [...$ids, ...$children];
        } while ($children !== []);

        return $ids;
    }

    /**
     * Módulos visibles para un cliente WEB o MOVIL (incluye los marcados como AMBOS).
     */
    public function scopeForPlatform(Builder $query, Platform $client): Builder
    {
        return $query->whereIn('platform', Platform::visibleFor($client));
    }
}
