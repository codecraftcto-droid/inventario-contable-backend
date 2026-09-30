<?php

namespace Modules\Inventories\Models;

use App\Enums\InventoryStatus;
use App\Models\Concerns\TracksAuthor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Companies\Models\Company;

class Inventory extends Model
{
    use TracksAuthor;

    /** Permiso para ver todos los inventarios sin estar asignado. */
    public const SEE_ALL_PERMISSION = 'INV:VER_TODOS';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => InventoryStatus::class,
            'closed_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Activos de la base contable cargada.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Lecturas de la toma de inventario.
     */
    public function scans(): HasMany
    {
        return $this->hasMany(InventoryScan::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Personal interno asignado a la toma de este inventario.
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'inventory_assignments')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * Inventarios que el usuario puede ver:
     *  - Usuario cliente: los de su empresa.
     *  - Personal con INV:VER_TODOS (administrador, contable): todos.
     *  - Resto del personal (p. ej. inventariador): solo los que tiene asignados.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isClientUser()) {
            return $query->where('company_id', $user->company_id);
        }

        if ($user->hasPermissionTo(self::SEE_ALL_PERMISSION)) {
            return $query;
        }

        return $query->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('inventory_assignments')
            ->whereColumn('inventory_assignments.inventory_id', 'inventories.id')
            ->where('inventory_assignments.user_id', $user->id));
    }

    /**
     * Misma regla que scopeVisibleTo, para un inventario ya cargado.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isClientUser()) {
            return $this->company_id === $user->company_id;
        }

        return $user->hasPermissionTo(self::SEE_ALL_PERMISSION) || $this->isAssignedTo($user);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assignees()->whereKey($user->id)->exists();
    }

    public function isClosed(): bool
    {
        return $this->status === InventoryStatus::CERRADO;
    }

    /**
     * Solo se registra (app y web) con la toma en curso.
     */
    public function acceptsRecords(): bool
    {
        return $this->status === InventoryStatus::EN_PROCESO;
    }

    /**
     * Pockets (zonas / tandas de conteo) del inventario.
     */
    public function pockets(): HasMany
    {
        return $this->hasMany(InventoryPocket::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
