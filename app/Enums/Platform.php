<?php

namespace App\Enums;

enum Platform: string
{
    case WEB = 'WEB';
    case MOVIL = 'MOVIL';
    case AMBOS = 'AMBOS';

    /**
     * Plataformas de módulo visibles para un cliente que inicia sesión desde $client.
     *
     * @return string[]
     */
    public static function visibleFor(self $client): array
    {
        return [$client->value, self::AMBOS->value];
    }

    /**
     * Plataformas con las que un cliente puede iniciar sesión (AMBOS es solo para módulos).
     *
     * @return string[]
     */
    public static function clientValues(): array
    {
        return [self::WEB->value, self::MOVIL->value];
    }
}
