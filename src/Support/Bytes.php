<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Utilidades para mostrar tamaños en bytes de forma legible.
 */
final class Bytes
{
    public const KB = 1024;
    public const MB = 1048576;
    public const GB = 1073741824;

    public static function human(int|float $bytes, int $decimals = 1): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        if ($i === 0) {
            return sprintf('%d %s', (int) $bytes, $units[$i]);
        }
        $formatted = number_format($bytes, $decimals, ',', '.');
        // "5,0 GB" -> "5 GB"
        $formatted = preg_replace('/,0+$/', '', $formatted) ?? $formatted;

        return $formatted . ' ' . $units[$i];
    }
}
