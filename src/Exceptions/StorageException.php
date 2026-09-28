<?php

declare(strict_types=1);

namespace App\Exceptions;

final class StorageException extends AppException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'No pudimos comunicarnos con el almacenamiento. Inténtalo de nuevo en unos segundos.', string $code = 'storage_error', array $details = [])
    {
        parent::__construct($message, $code, 502, $details);
    }
}
