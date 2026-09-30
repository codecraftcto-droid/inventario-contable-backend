<?php

return [

    /*
    | Bloqueo temporal: tras N intentos fallidos seguidos el usuario queda
    | bloqueado durante X minutos (el administrador puede desbloquearlo antes).
    */
    'max_login_attempts' => (int) env('SECURITY_MAX_LOGIN_ATTEMPTS', 5),

    'lockout_minutes' => (int) env('SECURITY_LOCKOUT_MINUTES', 15),

    /*
    | Vigencia del refresh token (días) por plataforma. Es deslizante: cada
    | renovación la extiende. En MOVIL es más larga porque la toma de inventario
    | se hace en almacenes donde puede no haber señal durante días.
    */
    'refresh_ttl_days' => [
        'WEB' => (int) env('SECURITY_REFRESH_TTL_WEB_DAYS', 1),
        'MOVIL' => (int) env('SECURITY_REFRESH_TTL_MOVIL_DAYS', 30),
    ],

    /*
    | Módulos (y sus submódulos) cuyos permisos nunca se pueden dar a un rol de
    | tipo CLIENTE: la administración del sistema es solo para personal interno.
    */
    'internal_only_modules' => ['SEG', 'EMP'],

];
