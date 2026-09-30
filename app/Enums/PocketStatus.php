<?php

namespace App\Enums;

/**
 * Avance de un pocket (zona / tanda de conteo).
 */
enum PocketStatus: string
{
    case PENDIENTE = 'PENDIENTE';   // Aún sin registros
    case EN_CONTEO = 'EN_CONTEO';   // Ya tiene registros
    case TERMINADO = 'TERMINADO';   // El inventariador (o el supervisor) lo dio por terminado
}
