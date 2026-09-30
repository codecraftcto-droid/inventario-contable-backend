<?php

namespace App\Enums;

enum AccessEvent: string
{
    case LOGIN_OK = 'LOGIN_OK';
    case LOGIN_FALLIDO = 'LOGIN_FALLIDO';
    case LOGOUT = 'LOGOUT';
    case BLOQUEO = 'BLOQUEO';
    case CAMBIO_CLAVE = 'CAMBIO_CLAVE';
}
