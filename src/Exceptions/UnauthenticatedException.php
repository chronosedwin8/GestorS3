<?php

declare(strict_types=1);

namespace App\Exceptions;

final class UnauthenticatedException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Tu sesión expiró. Vuelve a iniciar sesión.', string $code = 'unauthenticated', array $details = [])
    {
        parent::__construct($message, $code, 401, $details);
    }
}
