<?php

declare(strict_types=1);

namespace App\Support;

use Ramsey\Uuid\Uuid as RamseyUuid;

final class Uuid
{
    public static function v4(): string
    {
        return RamseyUuid::uuid4()->toString();
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
