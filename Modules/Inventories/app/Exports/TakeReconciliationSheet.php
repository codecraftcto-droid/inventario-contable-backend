<?php

namespace Modules\Inventories\Exports;

use App\Enums\ReconciliationStatus;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Services\ReconciliationService;

/**
 * Conciliación por producto: cantidad de la base vs. lo registrado en la toma, con su estado
 * (conciliado, faltante, sobrante, diferencia positiva / negativa). Se lee por bloques.
 * WithStrictNullComparison: sin esto la librería escribe los 0 como celdas vacías.
 */
class TakeReconciliationSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    public function __construct(private readonly Inventory $inventory) {}

    public function title(): string
    {
        return 'Conciliación';
    }

    public function query(): Builder
    {
        // Primero lo que requiere atención (faltantes, diferencias, sobrantes), luego lo conciliado.
        return app(ReconciliationService::class)->query($this->inventory)
            ->orderByRaw("CASE status WHEN 'FALTANTE' THEN 0 WHEN 'DIFERENCIA_NEGATIVA' THEN 1 WHEN 'DIFERENCIA_POSITIVA' THEN 2 WHEN 'SOBRANTE' THEN 3 ELSE 4 END")
            ->orderBy('code');
    }

    public function headings(): array
    {
        return ['CODIGO_PRODUCTO', 'NOMBRE_PRODUCTO', 'UND_MEDIDA', 'VALOR', 'CANTIDAD_BASE', 'CANTIDAD_REGISTRADA', 'DIFERENCIA', 'REGISTROS', 'ESTADO'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->name,
            $row->unit,
            $row->value === null ? null : (float) $row->value,
            (float) $row->expected,
            (float) $row->counted,
            (float) $row->difference,
            (int) $row->records,
            mb_strtoupper(ReconciliationStatus::from($row->status)->label()),
        ];
    }
}
