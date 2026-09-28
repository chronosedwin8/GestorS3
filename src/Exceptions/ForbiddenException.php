<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ForbiddenException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'No tienes permiso para realizar esta acción.', string $code = 'forbidden', array $details = [])
    {
        parent::__construct($message, $code, 403, $details);
    }
}
