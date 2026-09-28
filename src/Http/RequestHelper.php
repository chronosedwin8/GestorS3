<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Utilidades comunes sobre la petición HTTP.
 */
final class RequestHelper
{
    public static function path(ServerRequestInterface $request, string $basePath): string
    {
        $path = $request->getUri()->getPath();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }

    public static function wantsJson(ServerRequestInterface $request, string $basePath): bool
    {
        $path = self::path($request, $basePath);
        // Descargas: son navegaciones del navegador; los errores se muestran como página.
        $isNavigation = preg_match('#/(download|download-zip)$#', $path) === 1;
        if (!$isNavigation && (str_starts_with($path, '/api/') || preg_match('#^/s/[^/]+/(api|zip-info|files/[^/]+/preview)#', $path) === 1)) {
            return true;
        }

        return str_contains($request->getHeaderLine('Accept'), 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * Lista de strings desde el cuerpo (acepta array o cadena separada por comas).
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    public static function stringList(array $body, string $key): array
    {
        $value = $body[$key] ?? [];
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function str(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Ruta interna segura para redirigir tras el login (evita redirecciones abiertas).
     */
    public static function safeNext(?string $next): string
    {
        if ($next === null || $next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return '/';
        }

        return $next;
    }
}
