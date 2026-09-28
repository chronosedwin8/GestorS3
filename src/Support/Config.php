<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Acceso tipado a la configuración cargada desde config/settings.php.
 */
final class Config
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $path, int $default = 0): int
    {
        $value = $this->get($path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        return (bool) $this->get($path, $default);
    }

    public function isProduction(): bool
    {
        return $this->string('app.env') === 'production';
    }

    public function basePath(): string
    {
        return $this->string('app.base_path');
    }

    /**
     * URL absoluta a partir de una ruta interna ("/folders/x").
     */
    public function url(string $path = '/'): string
    {
        return $this->string('app.url') . '/' . ltrim($path, '/');
    }
}
