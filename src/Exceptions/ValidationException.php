<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Revisa los datos ingresados.', string $code = 'validation_failed', array $details = [])
    {
        parent::__construct($message, $code, 422, $details);
    }
}
