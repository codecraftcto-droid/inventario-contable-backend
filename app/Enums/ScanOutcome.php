<?php

namespace App\Enums;

/**
 * Resultado de una lectura en la toma de inventario (lo decide el servidor).
 */
enum ScanOutcome: string
{
    case FOUND = 'FOUND';          // Producto de la base contable (cada registro cuenta; las cantidades se suman)
    case DUPLICATE = 'DUPLICATE';  // Solo lecturas antiguas: antes un segundo registro no contaba
    case SURPLUS = 'SURPLUS';      // Código que no está en la base contable (sobrante)
}
