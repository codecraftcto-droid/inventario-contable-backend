<?php

namespace App\Enums;

/**
 * Estado de conservación del bien registrado en la toma.
 */
enum ScanCondition: string
{
    case BUENO = 'BUENO';
    case REGULAR = 'REGULAR';
    case MALO = 'MALO';
}
