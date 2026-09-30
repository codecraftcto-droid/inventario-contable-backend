<?php

namespace Modules\Inventories\Exports;

use App\Enums\ScanOutcome;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;

/**
 * Todos los registros de la toma, un registro por fila, con las columnas del Excel del
 * APK "App Suministros" (+ resultado y cantidad de fotos). Se lee por bloques.
 */
// WithStrictNullComparison: sin esto la librería escribe los 0 como celdas vacías.
class TakeRecordsSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle, WithStrictNullComparison
{
    public function __construct(private readonly Inventory $inventory) {}

    public function title(): string
    {
        return 'Registros';
    }

    public function query(): Builder
    {
        return InventoryScan::query()
            ->where('inventory_id', $this->inventory->id)
            ->with(['asset:id,nombre,unidad_medida', 'user:id,first_name,last_name', 'site:id,name'])
            ->withCount('photos')
            ->orderBy('scanned_at')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'ID', 'POCKET', 'INVENTARIADOR', 'FECHA_LECTURA', 'CODIGO_PRODUCTO', 'NOMBRE_PRODUCTO', 'UND_MEDIDA',
            'CANTIDAD', 'DETALLE_DEL_BIEN', 'ESTADO_CONSERVACION', 'UBICACION_PRODUCTO', 'OBSERVACIONES', 'RESULTADO', 'FOTOS',
        ];
    }

    /**
     * @param  InventoryScan  $scan
     */
    public function map($scan): array
    {
        return [
            $scan->id,
            $scan->pocket,
            $scan->user?->full_name,
            $scan->scanned_at->timezone(config('app.timezone'))->format('d/m/Y H:i:s'),
            $scan->code,
            $scan->asset?->nombre,
            $scan->asset?->unidad_medida,
            (float) $scan->quantity,
            $scan->detail,
            $scan->condition?->value,
            $scan->site?->name ?? $scan->location,
            $scan->observations,
            match ($scan->outcome) {
                ScanOutcome::FOUND => 'EN BASE',
                ScanOutcome::SURPLUS => 'SOBRANTE',
                ScanOutcome::DUPLICATE => 'REPETIDO',
            },
            $scan->photos_count,
        ];
    }
}
