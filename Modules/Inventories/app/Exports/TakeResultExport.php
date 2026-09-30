<?php

namespace Modules\Inventories\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Modules\Inventories\Models\Inventory;

/**
 * Resultado de la toma de inventario: resumen, conciliación por producto (base vs. registrado) y registros (columnas del APK).
 * Las hojas de detalle se leen por bloques (FromQuery), no se carga todo en memoria.
 */
class TakeResultExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly Inventory $inventory) {}

    public function sheets(): array
    {
        return [
            new TakeSummarySheet($this->inventory),
            new TakeReconciliationSheet($this->inventory),
            new TakeRecordsSheet($this->inventory),
        ];
    }
}
