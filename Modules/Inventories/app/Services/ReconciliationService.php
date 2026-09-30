<?php

namespace Modules\Inventories\Services;

use App\Enums\ReconciliationStatus;
use App\Enums\ScanOutcome;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventories\Models\Inventory;

/**
 * Conciliación de la toma contra la base contable, por código de producto:
 *
 *   registrado = suma de las cantidades de todos los registros del código (todas las sedes)
 *   base       = CANTIDAD de la base contable (vacía = 1)
 *
 *   CONCILIADO           registrado = base
 *   FALTANTE             está en la base y no tiene ningún registro
 *   SOBRANTE             tiene registros pero no está en la base
 *   DIFERENCIA_POSITIVA  registrado > base
 *   DIFERENCIA_NEGATIVA  0 < registrado < base
 *
 * Se calcula en la base de datos (agregados + UNION), así que funciona igual de rápido con
 * miles de productos y registros; el listado se pagina y filtra sobre la subconsulta.
 */
class ReconciliationService
{
    /**
     * Subconsulta con una fila por código: code, name, unit, value, expected, counted, records,
     * difference y status. Lista para filtrar, ordenar y paginar.
     */
    public function query(Inventory $inventory): Builder
    {
        $found = [ScanOutcome::FOUND->value, ScanOutcome::DUPLICATE->value];

        $counted = DB::table('inventory_scans')
            ->selectRaw('asset_id, SUM(quantity) AS counted, COUNT(*) AS records')
            ->where('inventory_id', $inventory->id)
            ->whereIn('outcome', $found)
            ->groupBy('asset_id');

        // Productos de la base (con o sin registros)
        $base = DB::table('assets AS a')
            ->leftJoinSub($counted, 's', 's.asset_id', '=', 'a.id')
            ->where('a.inventory_id', $inventory->id)
            ->selectRaw('1 AS in_base, a.codigo AS code, a.nombre AS name, a.unidad_medida AS unit, a.valor AS value')
            ->selectRaw('COALESCE(a.cantidad, 1) AS expected, COALESCE(s.counted, 0) AS counted, COALESCE(s.records, 0) AS records');

        // Códigos registrados que no están en la base
        $surplus = DB::table('inventory_scans')
            ->where('inventory_id', $inventory->id)
            ->where('outcome', ScanOutcome::SURPLUS->value)
            ->groupBy('code')
            ->selectRaw('0 AS in_base, code, NULL AS name, NULL AS unit, NULL AS value, 0 AS expected, SUM(quantity) AS counted, COUNT(*) AS records');

        $rows = DB::query()->fromSub($base->unionAll($surplus), 'r');

        return DB::query()->fromSub(
            $rows->select('r.*')->selectRaw('r.counted - r.expected AS difference')->selectRaw($this->statusCase().' AS status'),
            'c',
        );
    }

    /**
     * Cantidad de productos por estado (+ totales de unidades).
     *
     * @return array<string, mixed>
     */
    public function summary(Inventory $inventory): array
    {
        $byStatus = $this->query($inventory)
            ->selectRaw('status, COUNT(*) AS products, SUM(expected) AS expected, SUM(counted) AS counted')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $statuses = [];
        foreach (ReconciliationStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($byStatus[$status->value]->products ?? 0);
        }

        return [
            'statuses' => $statuses,
            'expected_units' => (float) $byStatus->sum('expected'),
            'counted_units' => (float) $byStatus->sum('counted'),
        ];
    }

    /**
     * Estado de cada fila. Las cantidades se comparan con tolerancia (decimales de la suma).
     * Una base con cantidad 0 y sin registros también está conciliada.
     */
    private function statusCase(): string
    {
        return "CASE
            WHEN r.in_base = 0 THEN '".ReconciliationStatus::SOBRANTE->value."'
            WHEN ABS(r.counted - r.expected) < 0.005 THEN '".ReconciliationStatus::CONCILIADO->value."'
            WHEN r.records = 0 THEN '".ReconciliationStatus::FALTANTE->value."'
            WHEN r.counted > r.expected THEN '".ReconciliationStatus::DIFERENCIA_POSITIVA->value."'
            ELSE '".ReconciliationStatus::DIFERENCIA_NEGATIVA->value."'
        END";
    }
}
