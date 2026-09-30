<?php

namespace App\Enums;

/**
 * Tipo de usuario. No se guarda: se deduce de users.company_id.
 *  - INTERNO: sin empresa. Personal de R&R, ve la información de todas las empresas.
 *  - CLIENTE: con empresa. Solo ve la información de su empresa y nunca puede
 *             tener permisos de administración (config security.internal_only_modules).
 */
enum UserType: string
{
    case INTERNO = 'INTERNO';
    case CLIENTE = 'CLIENTE';
}
