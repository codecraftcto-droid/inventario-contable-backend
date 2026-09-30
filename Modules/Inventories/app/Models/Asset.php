<?php

namespace Modules\Inventories\Models;

use App\Models\Concerns\TracksAuthor;
use App\Enums\ScanOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activo de la base de datos contable de un inventario.
 */
class Asset extends Model
{
    use TracksAuthor;

    protected $fillable = [
        'inventory_id',
        'codigo',
        'nombre',
        'unidad_medida',
        'cantidad',   // Lo que debería haber (vacío = 1); la toma se concilia contra esto
        'valor',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'valor' => 'decimal:2',
        ];
    }

    /**
     * Cantidad esperada para la conciliación (vacía = 1).
     */
    public function expectedQuantity(): float
    {
        return $this->cantidad === null ? 1.0 : (float) $this->cantidad;
    }

    /**
     * Códigos siempre en mayúsculas y sin espacios: así coinciden con lo que lee el escáner.
     */
    protected function codigo(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : mb_strtoupper(trim($value)));
    }

    /**
     * Activos que aún no tienen una lectura "encontrado" en la toma del inventario.
     * NOT EXISTS usa el índice (inventory_id, asset_id, outcome) de las lecturas.
     */
    public function scopeMissingIn(Builder $query, Inventory $inventory): Builder
    {
        return $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
            ->from('inventory_scans')
            ->where('inventory_scans.inventory_id', $inventory->id)
            ->whereColumn('inventory_scans.asset_id', 'assets.id')
            ->where('inventory_scans.outcome', ScanOutcome::FOUND->value));
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
