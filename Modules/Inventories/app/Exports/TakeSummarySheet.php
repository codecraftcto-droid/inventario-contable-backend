<?php

namespace Modules\Inventories\Exports;

use App\Enums\ReconciliationStatus;
use App\Enums\ScanOutcome;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Services\ReconciliationService;

// WithStrictNullComparison: sin esto la librería escribe los 0 como celdas vacías.
class TakeSummarySheet implements FromArray, ShouldAutoSize, WithTitle, WithStrictNullComparison
{
    public function __construct(private readonly Inventory $inventory) {}

    public function title(): string
    {
        return 'Resumen';
    }

    public function array(): array
    {
        $inventory = $this->inventory->loadMissing('company:id,name');
        $byOutcome = $inventory->scans()
            ->selectRaw('outcome, COUNT(*) as records, COUNT(DISTINCT asset_id) as assets, COUNT(DISTINCT code) as codes, SUM(quantity) as units')
            ->groupBy('outcome')
            ->toBase()
            ->get()
            ->keyBy('outcome');
        $total = $inventory->assets()->count();
        $found = (int) ($byOutcome[ScanOutcome::FOUND->value]->assets ?? 0);

        $reconciliation = app(ReconciliationService::class)->summary($inventory);

        return [
            ['Empresa', $inventory->company?->name],
            ['Inventario', "{$inventory->code} - {$inventory->name}"],
            ['Estado', match ($inventory->status->value) { 'PENDIENTE' => 'Pendiente', 'EN_PROCESO' => 'En proceso', 'CERRADO' => 'Cerrado', default => $inventory->status->value }],
            ['Generado', now()->format('d/m/Y H:i')],
            [],
            ['Productos en la base contable', $total],
            ['Productos encontrados', $found],
            ['Productos faltantes', max($total - $found, 0)],
            ['Unidades contadas (productos de la base)', (float) ($byOutcome[ScanOutcome::FOUND->value]->units ?? 0)],
            ['Códigos sobrantes (fuera de la base)', (int) ($byOutcome[ScanOutcome::SURPLUS->value]->codes ?? 0)],
            ['Registros de la toma', (int) $byOutcome->sum('records')],
            ['Avance', $total > 0 ? round($found * 100 / $total, 1).' %' : '0 %'],
            [],
            ['CONCILIACIÓN (productos)', ''],
            ...array_map(
                fn (ReconciliationStatus $status) => [$status->label(), $reconciliation['statuses'][$status->value]],
                ReconciliationStatus::cases(),
            ),
            ['Unidades según la base', $reconciliation['expected_units']],
            ['Unidades registradas', $reconciliation['counted_units']],
        ];
    }
}
