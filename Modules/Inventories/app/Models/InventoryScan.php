<?php

namespace Modules\Inventories\Models;

use App\Enums\ScanCondition;
use App\Enums\ScanOutcome;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Companies\Models\Site;

class InventoryScan extends Model
{
    protected $fillable = [
        'inventory_id',
        'asset_id',
        'client_uuid',
        'code',
        'outcome',
        'quantity',
        'condition',
        'site_id',
        'pocket_id',
        'location',
        'pocket',
        'detail',
        'observations',
        'user_id',
        'device_id',
        'scanned_at',
        'client_updated_at',
    ];

    protected static function booted(): void
    {
        // Se deja constancia de la eliminación para que los teléfonos la reciban en la
        // sincronización de cambios (y un reenvío atrasado no vuelva a crear el registro).
        static::deleted(function (self $scan): void {
            DB::table('inventory_scan_deletions')->insertOrIgnore([
                'inventory_id' => $scan->inventory_id,
                'client_uuid' => $scan->client_uuid,
                'deleted_at' => now(),
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'outcome' => ScanOutcome::class,
            'condition' => ScanCondition::class,
            'quantity' => 'decimal:2',
            'scanned_at' => 'datetime',
            'client_updated_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pocketModel(): BelongsTo
    {
        return $this->belongsTo(InventoryPocket::class, 'pocket_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InventoryScanPhoto::class);
    }

    /**
     * Borra los archivos de las fotos (las filas caen en cascada con el registro).
     */
    public function deleteFiles(): void
    {
        foreach ($this->photos()->get(['disk', 'path']) as $photo) {
            Storage::disk($photo->disk)->delete($photo->path);
        }
    }
}
