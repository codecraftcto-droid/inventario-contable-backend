<?php

namespace App\Enums;

/**
 * Ciclo de vida de un inventario:
 *   PENDIENTE → (Iniciar) → EN_PROCESO ⇄ (Pausar / Reanudar) PAUSADO → (Finalizar) → CERRADO.
 * Solo se registra con el inventario EN_PROCESO. CERRADO se muestra como "Finalizado".
 */
enum InventoryStatus: string
{
    case PENDIENTE = 'PENDIENTE';     // Registrado; se prepara (base contable, pockets) pero aún no se toma
    case EN_PROCESO = 'EN_PROCESO';   // Toma en curso: los inventariadores registran
    case PAUSADO = 'PAUSADO';         // Toma detenida temporalmente: no se registra
    case CERRADO = 'CERRADO';         // Finalizado: ya no admite cargas ni registros

    public function label(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::EN_PROCESO => 'En proceso',
            self::PAUSADO => 'Pausado',
            self::CERRADO => 'Finalizado',
        };
    }
}
