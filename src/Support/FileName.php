<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Saneamiento de nombres de archivos y carpetas visibles para el usuario.
 * Conserva acentos y espacios; elimina caracteres de control y separadores de ruta.
 */
final class FileName
{
    public const MAX_BYTES = 255;

    /**
     * Sanea un nombre de archivo o carpeta. Devuelve cadena vacía si no queda nada válido.
     */
    public static function sanitize(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8, ISO-8859-1');
        }
        // Normalizar a NFC cuando intl esté disponible (macOS entrega NFD).
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }
        // Caracteres de control (C0, DEL, C1) y separadores de ruta.
        $name = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\'], '-', $name);
        // Espacios múltiples -> uno.
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name);
        // Evitar "." y ".." y puntos/espacios finales (problemáticos en Windows).
        $name = rtrim($name, ' .');
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }

        return self::truncate($name, self::MAX_BYTES);
    }

    /**
     * Recorta a un máximo de bytes UTF-8 conservando la extensión.
     */
    public static function truncate(string $name, int $maxBytes): string
    {
        if (strlen($name) <= $maxBytes) {
            return $name;
        }
        $ext = self::extension($name);
        $suffix = $ext !== '' ? '.' . $ext : '';
        if (strlen($suffix) >= $maxBytes) {
            $suffix = '';
        }
        $base = $suffix !== '' ? mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)) : $name;
        while (strlen($base . $suffix) > $maxBytes && $base !== '') {
            $base = mb_substr($base, 0, -1);
        }

        return rtrim($base) . $suffix;
    }

    /**
     * Extensión en minúsculas sin punto ("" si no tiene). Los archivos ocultos (".env") no tienen extensión.
     */
    public static function extension(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos === false || $pos === 0) {
            return '';
        }
        $ext = mb_strtolower(substr($name, $pos + 1));
        if ($ext === '' || mb_strlen($ext) > 32 || preg_match('/\s/u', $ext)) {
            return '';
        }

        return $ext;
    }

    /**
     * Genera "nombre (n).ext" para conservar ambos archivos.
     */
    public static function withCounter(string $name, int $n): string
    {
        $ext = self::extension($name);
        $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
        // Si ya termina en " (k)", reemplazar el contador.
        $base = preg_replace('/ \(\d+\)$/u', '', $base) ?? $base;
        $candidate = sprintf('%s (%d)%s', $base, $n, $ext !== '' ? '.' . $ext : '');

        return self::truncate($candidate, self::MAX_BYTES);
    }

    /**
     * Divide una ruta relativa ("A/B/archivo.txt") en segmentos de carpeta saneados, sin el archivo.
     *
     * @return list<string>
     */
    public static function directorySegments(string $relativePath, ?string $fileName = null): array
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $parts = array_values(array_filter(explode('/', $relativePath), static fn (string $p): bool => $p !== ''));
        if ($parts !== [] && $fileName !== null) {
            $last = end($parts);
            if ($last === $fileName || self::sanitize($last) === self::sanitize($fileName)) {
                array_pop($parts);
            }
        }
        $segments = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                continue;
            }
            $clean = self::sanitize($part);
            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        return array_slice($segments, 0, 32);
    }

    /**
     * Tipo de vista previa soportada según MIME/extensión: image, pdf, video, audio, text o null.
     */
    public static function previewKind(string $mime, string $extension): ?string
    {
        $mime = strtolower($mime);
        $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif', 'ico'];
        $videoExt = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];
        $audioExt = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus'];
        $textExt = ['txt', 'csv', 'md', 'log', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'sql', 'html', 'htm',
            'css', 'js', 'ts', 'php', 'py', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rb', 'sh', 'bat', 'ps1', 'tsv', 'env', ];

        return match (true) {
            $extension === 'pdf' || $mime === 'application/pdf' => 'pdf',
            in_array($extension, $imageExt, true) || (str_starts_with($mime, 'image/') && $extension !== 'tif' && $extension !== 'tiff' && $extension !== 'heic') => 'image',
            in_array($extension, $videoExt, true) => 'video',
            in_array($extension, $audioExt, true) || str_starts_with($mime, 'audio/') => 'audio',
            in_array($extension, $textExt, true) || str_starts_with($mime, 'text/') => 'text',
            default => null,
        };
    }

    /**
     * MIME seguro para servir en vista previa inline.
     */
    public static function previewMime(string $kind, string $mime, string $extension): string
    {
        return match ($kind) {
            'pdf' => 'application/pdf',
            'text' => 'text/plain; charset=utf-8',
            'image' => $extension === 'svg' ? 'image/svg+xml' : ($mime !== '' ? $mime : 'image/' . $extension),
            default => $mime !== '' ? $mime : 'application/octet-stream',
        };
    }

    /**
     * Normaliza el MIME declarado por el navegador.
     */
    public static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if ($mime === '' || !preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $mime) || strlen($mime) > 127) {
            return 'application/octet-stream';
        }

        return $mime;
    }
}
