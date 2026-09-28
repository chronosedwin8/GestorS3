<?php

declare(strict_types=1);

namespace App\Exceptions;

final class TooManyRequestsException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Demasiados intentos. Espera un momento e inténtalo de nuevo.', string $code = 'too_many_requests', array $details = [])
    {
        parent::__construct($message, $code, 429, $details);
    }
}
