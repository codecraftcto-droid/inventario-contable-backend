<?php

namespace Modules\Security\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Fallo de autenticación con mensaje y código HTTP para el cliente
 * (401 credenciales, 403 cuenta inactiva, 423 usuario bloqueado).
 */
class AuthFailedException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 401)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
