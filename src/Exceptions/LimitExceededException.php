<?php

declare(strict_types=1);

namespace App\Exceptions;

final class LimitExceededException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'Se superó un límite permitido.', string $code = 'limit_exceeded', array $details = [])
    {
        parent::__construct($message, $code, 413, $details);
    }
}
