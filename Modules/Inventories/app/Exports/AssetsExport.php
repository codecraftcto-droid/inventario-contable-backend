<?php

namespace Modules\Inventories\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Inventories\Models\Asset;
use Modules\Inventories\Models\Inventory;

/**
 * Base contable cargada de un inventario, con las mismas columnas de la plantilla
 * (se puede corregir en Excel y volver a subir).
 */
class AssetsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly Inventory $inventory) {}

    public function query(): Builder
    {
        return Asset::query()->where('inventory_id', $this->inventory->id)->orderBy('codigo');
    }

    public function headings(): array
    {
        return AssetsTemplateExport::HEADINGS;
    }

    /**
     * @param  Asset  $asset
     */
    public function map($asset): array
    {
        return [$asset->codigo, $asset->nombre, $asset->unidad_medida, $asset->cantidad === null ? null : (float) $asset->cantidad, $asset->valor === null ? null : (float) $asset->valor];
    }
}
