<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ConflictException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Ya existe un elemento con ese nombre.', string $code = 'conflict', array $details = [])
    {
        parent::__construct($message, $code, 409, $details);
    }
}
