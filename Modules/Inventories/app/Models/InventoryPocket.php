<?php

namespace Modules\Inventories\Models;

use App\Enums\PocketStatus;
use App\Models\Concerns\TracksAuthor;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Companies\Models\Site;

/**
 * Pocket: zona o tanda de conteo del inventario, asignada a uno o varios inventariadores.
 */
class InventoryPocket extends Model
{
    use TracksAuthor;

    protected $fillable = ['inventory_id', 'site_id', 'code', 'name', 'status'];

    protected $attributes = ['status' => 'PENDIENTE'];

    protected function casts(): array
    {
        return [
            'status' => PocketStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Código en mayúsculas y sin espacios: "p-01 " y "P-01" son el mismo pocket.
     */
    protected function code(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : mb_strtoupper(trim($value)));
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'inventory_pocket_user')->withPivot('assigned_by')->withTimestamps();
    }

    public function scans(): HasMany
    {
        return $this->hasMany(InventoryScan::class, 'pocket_id');
    }
}
