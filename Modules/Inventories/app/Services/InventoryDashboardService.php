<?php

namespace Modules\Inventories\Services;

use App\Enums\PocketStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ScanCondition;
use App\Enums\ScanOutcome;
use Illuminate\Support\Facades\DB;
use Modules\Inventories\Models\Inventory;

/**
 * Indicadores y series para los gráficos del dashboard de un inventario.
 * Todo se agrega en la base de datos (COUNT/SUM/GROUP BY), así que no depende
 * de cuántos productos o registros tenga la toma.
 */
class InventoryDashboardService
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    /**
     * @return array<string, mixed>
     */
    public function stats(Inventory $inventory): array
    {
        $scans = DB::table('inventory_scans')->where('inventory_id', $inventory->id);
        $counting = [ScanOutcome::FOUND->value, ScanOutcome::DUPLICATE->value];

        $products = $inventory->assets()->count();
        $expectedUnits = (float) $inventory->assets()->sum(DB::raw('COALESCE(cantidad, 1)'));
        $found = (int) (clone $scans)->whereIn('outcome', $counting)->distinct()->count('asset_id');
        $totals = (clone $scans)->selectRaw('COUNT(*) AS records, COALESCE(SUM(quantity), 0) AS units, MIN(scanned_at) AS first_at, MAX(scanned_at) AS last_at')->first();

        return [
            'base' => ['products' => $products, 'units' => $expectedUnits],
            'records' => (int) $totals->records,
            'units' => (float) $totals->units,
            'found_products' => $found,
            'progress' => $products > 0 ? round($found * 100 / $products, 1) : 0,
            'first_scan_at' => $totals->first_at,
            'last_scan_at' => $totals->last_at,
            'assignees' => $inventory->assignees()->count(),
            'reconciliation' => $this->reconciliationBreakdown($inventory),
            'timeline' => $this->timeline($inventory, $products),
            'by_condition' => $this->byCondition($inventory),
            'by_site' => $this->bySite($inventory),
            'by_user' => $this->byUser($inventory),
            'pockets' => $this->pockets($inventory),
            'top_differences' => $this->topDifferences($inventory),
        ];
    }

    /**
     * Productos y unidades por estado de conciliación (en el orden del enum).
     */
    private function reconciliationBreakdown(Inventory $inventory): array
    {
        $rows = $this->reconciliation->query($inventory)
            ->selectRaw('status, COUNT(*) AS products, SUM(expected) AS expected, SUM(counted) AS counted')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return collect(ReconciliationStatus::cases())->map(fn (ReconciliationStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'products' => (int) ($rows[$status->value]->products ?? 0),
            'expected' => (float) ($rows[$status->value]->expected ?? 0),
            'counted' => (float) ($rows[$status->value]->counted ?? 0),
        ])->all();
    }

    /**
     * Por día: registros, unidades, productos de la base encontrados por primera vez
     * y avance acumulado (% de la base con al menos un registro).
     */
    private function timeline(Inventory $inventory, int $products): array
    {
        $daily = DB::table('inventory_scans')
            ->where('inventory_id', $inventory->id)
            ->selectRaw('DATE(scanned_at) AS day, COUNT(*) AS records, SUM(quantity) AS units')
            ->groupByRaw('DATE(scanned_at)')
            ->get()
            ->keyBy('day');

        // Día en que cada producto de la base se registró por primera vez
        $firstSeen = DB::table('inventory_scans')
            ->where('inventory_id', $inventory->id)
            ->whereIn('outcome', [ScanOutcome::FOUND->value, ScanOutcome::DUPLICATE->value])
            ->whereNotNull('asset_id')
            ->selectRaw('asset_id, MIN(DATE(scanned_at)) AS day')
            ->groupBy('asset_id');

        $newProducts = DB::query()->fromSub($firstSeen, 'f')
            ->selectRaw('day, COUNT(*) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = $daily->keys()->merge($newProducts->keys())->unique()->sort()->values();
        $accumulated = 0;

        return $days->map(function (string $day) use ($daily, $newProducts, $products, &$accumulated): array {
            $accumulated += (int) ($newProducts[$day] ?? 0);

            return [
                'date' => $day,
                'records' => (int) ($daily[$day]->records ?? 0),
                'units' => (float) ($daily[$day]->units ?? 0),
                'new_products' => (int) ($newProducts[$day] ?? 0),
                'progress' => $products > 0 ? round($accumulated * 100 / $products, 1) : 0,
            ];
        })->all();
    }

    private function byCondition(Inventory $inventory): array
    {
        $rows = DB::table('inventory_scans')
            ->where('inventory_id', $inventory->id)
            ->selectRaw('condition, COUNT(*) AS records, SUM(quantity) AS units')
            ->groupBy('condition')
            ->get()
            ->keyBy(fn ($row) => $row->condition ?? 'SIN_DATO');

        $result = collect(ScanCondition::cases())->map(fn (ScanCondition $condition): array => [
            'condition' => $condition->value,
            'records' => (int) ($rows[$condition->value]->records ?? 0),
            'units' => (float) ($rows[$condition->value]->units ?? 0),
        ]);

        // Registros antiguos sin estado de conservación
        if (isset($rows['SIN_DATO'])) {
            $result->push(['condition' => 'SIN_DATO', 'records' => (int) $rows['SIN_DATO']->records, 'units' => (float) $rows['SIN_DATO']->units]);
        }

        return $result->all();
    }

    private function bySite(Inventory $inventory): array
    {
        $site = "COALESCE(sites.name, inventory_scans.location, 'Sin ubicación')";

        return DB::table('inventory_scans')
            ->leftJoin('sites', 'sites.id', '=', 'inventory_scans.site_id')
            ->where('inventory_scans.inventory_id', $inventory->id)
            ->selectRaw("$site AS site, COUNT(*) AS records, SUM(inventory_scans.quantity) AS units")
            ->groupByRaw($site)
            ->orderByDesc('units')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => ['site' => $row->site, 'records' => (int) $row->records, 'units' => (float) $row->units])
            ->all();
    }

    private function byUser(Inventory $inventory): array
    {
        return DB::table('inventory_scans')
            ->leftJoin('users', 'users.id', '=', 'inventory_scans.user_id')
            ->where('inventory_scans.inventory_id', $inventory->id)
            ->selectRaw('inventory_scans.user_id, users.first_name, users.last_name, COUNT(*) AS records, SUM(inventory_scans.quantity) AS units, MAX(inventory_scans.scanned_at) AS last_scan_at')
            ->groupBy('inventory_scans.user_id', 'users.first_name', 'users.last_name')
            ->orderByDesc('records')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'user' => $row->first_name ? trim("{$row->first_name} {$row->last_name}") : 'Usuario eliminado',
                'records' => (int) $row->records,
                'units' => (float) $row->units,
                'last_scan_at' => $row->last_scan_at,
            ])
            ->all();
    }

    private function pockets(Inventory $inventory): array
    {
        $byStatus = $inventory->pockets()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'statuses' => collect(PocketStatus::cases())
                ->mapWithKeys(fn (PocketStatus $status): array => [$status->value => (int) ($byStatus[$status->value] ?? 0)])
                ->all(),
        ];
    }

    /**
     * Productos con mayor diferencia (en unidades) contra la base, para atenderlos primero.
     */
    private function topDifferences(Inventory $inventory): array
    {
        return $this->reconciliation->query($inventory)
            ->where('status', '!=', ReconciliationStatus::CONCILIADO->value)
            ->orderByRaw('ABS(difference) DESC')
            ->orderBy('code')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'code' => $row->code,
                'name' => $row->name,
                'expected' => (float) $row->expected,
                'counted' => (float) $row->counted,
                'difference' => (float) $row->difference,
                'status' => $row->status,
            ])
            ->all();
    }
}
