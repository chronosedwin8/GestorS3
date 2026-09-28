<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Construye cabeceras Content-Disposition compatibles (RFC 6266 / RFC 5987) con nombres UTF-8.
 */
final class ContentDisposition
{
    public static function build(string $filename, bool $inline = false): string
    {
        $type = $inline ? 'inline' : 'attachment';
        $ascii = self::asciiFallback($filename);

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($filename));
    }

    public static function asciiFallback(string $filename): string
    {
        $ascii = $filename;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
            if (is_string($converted) && $converted !== '') {
                $ascii = $converted;
            }
        }
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $ascii) ?? 'archivo';
        $ascii = str_replace(['"', '\\', '%'], '_', $ascii);

        return $ascii !== '' ? $ascii : 'archivo';
    }
}
