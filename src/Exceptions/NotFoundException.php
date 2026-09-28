<?php

declare(strict_types=1);

namespace App\Exceptions;

final class NotFoundException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'No encontramos lo que buscas. Puede que se haya eliminado o que no tengas acceso.', string $code = 'not_found', array $details = [])
    {
        parent::__construct($message, $code, 404, $details);
    }
}
