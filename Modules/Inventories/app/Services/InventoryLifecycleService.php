<?php

namespace Modules\Inventories\Services;

use App\Enums\InventoryStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventories\Models\Inventory;

/**
 * Botones del inventario: Iniciar, Pausar, Reanudar y Finalizar.
 *
 *   PENDIENTE ──Iniciar──▶ EN_PROCESO ──Pausar──▶ PAUSADO
 *                              ▲   └──────Reanudar──┘
 *                              └─ Finalizar (desde cualquier estado abierto) ─▶ CERRADO
 *
 * Cada cambio queda en inventory_status_logs (quién y cuándo).
 */
class InventoryLifecycleService
{
    /** Acción => [estados desde los que se permite, estado resultante, mensaje]. */
    private const TRANSITIONS = [
        'INICIAR' => [[InventoryStatus::PENDIENTE], InventoryStatus::EN_PROCESO, 'Inventario iniciado: ya se puede registrar desde los teléfonos.'],
        'PAUSAR' => [[InventoryStatus::EN_PROCESO], InventoryStatus::PAUSADO, 'Inventario en pausa: no se aceptan registros hasta reanudarlo.'],
        'REANUDAR' => [[InventoryStatus::PAUSADO], InventoryStatus::EN_PROCESO, 'Inventario reanudado.'],
        'FINALIZAR' => [[InventoryStatus::PENDIENTE, InventoryStatus::EN_PROCESO, InventoryStatus::PAUSADO], InventoryStatus::CERRADO, 'Inventario finalizado.'],
    ];

    public function apply(Inventory $inventory, string $action, User $user): string
    {
        [$from, $to, $message] = self::TRANSITIONS[$action];

        return DB::transaction(function () use ($inventory, $action, $user, $from, $to, $message): string {
            // Bloqueo: un lote de registros entrando no se cruza con el cambio de estado.
            $locked = Inventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages([
                    'status' => "No se puede {$this->verb($action)} un inventario {$this->current($locked->status)}.",
                ]);
            }

            $now = now();
            $changes = ['status' => $to];
            match ($action) {
                'INICIAR' => $changes += ['started_at' => $locked->started_at ?? $now, 'started_by' => $locked->started_by ?? $user->id],
                'PAUSAR' => $changes += ['paused_at' => $now],
                'REANUDAR' => $changes += ['paused_at' => null],
                'FINALIZAR' => $changes += ['closed_at' => $now, 'closed_by' => $user->id, 'paused_at' => null],
            };

            DB::table('inventory_status_logs')->insert([
                'inventory_id' => $locked->id,
                'action' => $action,
                'from_status' => $locked->status->value,
                'to_status' => $to->value,
                'user_id' => $user->id,
                'created_at' => $now,
            ]);
            $inventory->forceFill($changes)->save();

            return $message;
        });
    }

    private function verb(string $action): string
    {
        return ['INICIAR' => 'iniciar', 'PAUSAR' => 'pausar', 'REANUDAR' => 'reanudar', 'FINALIZAR' => 'finalizar'][$action];
    }

    private function current(InventoryStatus $status): string
    {
        return mb_strtolower($status->label());
    }
}
