<?php

namespace Modules\Inventories\Services;

use App\Enums\ScanOutcome;
use Illuminate\Support\Facades\DB;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;

/**
 * Anula una lectura equivocada de la toma.
 *
 * Si la lectura anulada era la que contaba (encontrado o sobrante) y el mismo
 * activo/código se había leído otra vez, la repetida más antigua pasa a ocupar su
 * lugar: el activo sigue figurando como encontrado.
 */
class ScanRemovalService
{
    public function remove(Inventory $inventory, InventoryScan $scan): void
    {
        DB::transaction(function () use ($inventory, $scan): void {
            // Mismo bloqueo que al registrar lecturas: evita carreras con un lote entrando.
            Inventory::query()->whereKey($inventory->id)->lockForUpdate()->first();

            $scan->deleteFiles();   // Fotos del registro (las filas caen en cascada)
            $scan->delete();

            if ($scan->outcome === ScanOutcome::DUPLICATE) {
                return;
            }

            $replacement = InventoryScan::query()
                ->where('inventory_id', $inventory->id)
                ->where('outcome', ScanOutcome::DUPLICATE)
                ->when(
                    $scan->outcome === ScanOutcome::FOUND,
                    fn ($query) => $query->where('asset_id', $scan->asset_id),
                    fn ($query) => $query->whereNull('asset_id')->where('code', $scan->code),
                )
                ->orderBy('scanned_at')
                ->orderBy('id')
                ->first();

            $replacement?->update(['outcome' => $scan->outcome]);
        });
    }
}
