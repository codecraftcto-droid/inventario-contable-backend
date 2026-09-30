<?php

namespace App\Enums;

/**
 * Estado de cada producto al comparar la toma (lo registrado desde los teléfonos y la web)
 * con la base contable cargada.
 */
enum ReconciliationStatus: string
{
    case CONCILIADO = 'CONCILIADO';                     // Registrado = cantidad de la base
    case FALTANTE = 'FALTANTE';                         // Está en la base y no tiene ningún registro
    case SOBRANTE = 'SOBRANTE';                         // Registrado pero no está en la base
    case DIFERENCIA_POSITIVA = 'DIFERENCIA_POSITIVA';   // Registrado > base (p. ej. base 5, registrado 6)
    case DIFERENCIA_NEGATIVA = 'DIFERENCIA_NEGATIVA';   // 0 < registrado < base (p. ej. base 5, registrado 4)

    public function label(): string
    {
        return match ($this) {
            self::CONCILIADO => 'Conciliado',
            self::FALTANTE => 'Faltante',
            self::SOBRANTE => 'Sobrante',
            self::DIFERENCIA_POSITIVA => 'Diferencia positiva',
            self::DIFERENCIA_NEGATIVA => 'Diferencia negativa',
        };
    }
}
