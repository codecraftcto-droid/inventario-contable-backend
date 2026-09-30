<?php

namespace Modules\Inventories\Services;

use App\Enums\ScanOutcome;
use Illuminate\Support\Facades\DB;
use Modules\Inventories\Models\Inventory;

/**
 * Mantiene los registros de la toma enlazados con la base contable cuando la base cambia
 * DESPUÉS de haber registrado (se vuelve a cargar el Excel, se agrega, edita o borra un producto):
 *  - un código registrado como sobrante que ahora sí está en la base pasa a "en base";
 *  - un código cuyo producto se quitó de la base pasa a sobrante.
 * Así un mismo producto nunca figura a la vez como faltante y como sobrante.
 */
class ScanRelinker
{
    /**
     * @param  string[]  $codes  Códigos (ya normalizados) que cambiaron en la base.
     */
    public function relink(Inventory $inventory, array $codes): int
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if ($codes === []) {
            return 0;
        }

        $assetId = '(SELECT a.id FROM assets a WHERE a.inventory_id = inventory_scans.inventory_id AND a.codigo = inventory_scans.code)';
        $updated = 0;

        foreach (array_chunk($codes, 500) as $chunk) {
            $updated += DB::table('inventory_scans')
                ->where('inventory_id', $inventory->id)
                ->whereIn('code', $chunk)
                ->update([
                    'asset_id' => DB::raw($assetId),
                    'outcome' => DB::raw("CASE WHEN {$assetId} IS NULL THEN '".ScanOutcome::SURPLUS->value."' ELSE '".ScanOutcome::FOUND->value."' END"),
                    'updated_at' => now(),   // Así los teléfonos reciben el cambio en su próxima sincronización
                ]);
        }

        return $updated;
    }
}
