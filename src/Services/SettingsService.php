<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Support\Config;

/**
 * Límites efectivos: valores de .env sobrescritos por los ajustes guardados por el administrador.
 */
final class SettingsService
{
    /** Claves editables desde el panel => clave de configuración por defecto. */
    public const EDITABLE = [
        'max_file_bytes' => 'limits.max_file_bytes',
        'folder_max_bytes' => 'limits.folder_max_bytes',
        'zip_max_bytes' => 'limits.zip_max_bytes',
        'zip_max_files' => 'limits.zip_max_files',
        'blocked_extensions' => 'limits.blocked_extensions',
        'internal_domains' => 'app.internal_domains',
    ];

    /** @var array<string, string|null>|null */
    private ?array $overrides = null;

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepository $repository,
    ) {
    }

    public function maxFileBytes(): int
    {
        return (int) $this->value('max_file_bytes');
    }

    public function folderMaxBytes(): int
    {
        return (int) $this->value('folder_max_bytes');
    }

    public function zipMaxBytes(): int
    {
        return (int) $this->value('zip_max_bytes');
    }

    public function zipMaxFiles(): int
    {
        return (int) $this->value('zip_max_files');
    }

    public function singleMaxBytes(): int
    {
        return $this->config->int('limits.single_max_bytes', 15728640);
    }

    public function partSizeBytes(): int
    {
        return $this->config->int('limits.part_size_bytes', 8388608);
    }

    /**
     * @return list<string>
     */
    public function blockedExtensions(): array
    {
        return self::parseExtensions((string) $this->value('blocked_extensions'));
    }

    public function isBlocked(string $extension): bool
    {
        return $extension !== '' && in_array(strtolower($extension), $this->blockedExtensions(), true);
    }

    /**
     * Dominios de correo de la institución (sus cuentas nuevas son "usuario", las demás "externo").
     *
     * @return list<string>
     */
    public function internalDomains(): array
    {
        return self::parseDomains((string) $this->value('internal_domains'));
    }

    /**
     * Tipo de cuenta para un correo nuevo invitado desde una carpeta.
     */
    public function roleForEmail(string $email): string
    {
        $domain = strtolower(substr((string) strrchr($email, '@'), 1));
        foreach ($this->internalDomains() as $internal) {
            if ($domain === $internal || str_ends_with($domain, '.' . $internal)) {
                return 'user';
            }
        }

        return 'external';
    }

    /**
     * @return list<string>
     */
    public static function parseDomains(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower($value)) ?: [];
        $parts = array_map(static fn (string $p): string => ltrim(trim($p), '@'), $parts);

        return array_values(array_unique(array_filter($parts, static fn (string $p): bool => preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $p) === 1)));
    }

    /**
     * @return list<string>
     */
    public static function parseExtensions(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower($value)) ?: [];
        $parts = array_map(static fn (string $p): string => ltrim(trim($p), '.'), $parts);

        return array_values(array_unique(array_filter($parts, static fn (string $p): bool => $p !== '' && preg_match('/^[a-z0-9]{1,32}$/', $p) === 1)));
    }

    /**
     * Valores efectivos y si provienen del panel o de .env.
     *
     * @return array<string, array{value: string, overridden: bool, default: string}>
     */
    public function describe(): array
    {
        $out = [];
        $overrides = $this->overrides();
        foreach (self::EDITABLE as $key => $configKey) {
            $default = $this->config->string($configKey);
            $out[$key] = [
                'value' => (string) $this->value($key),
                'overridden' => array_key_exists($key, $overrides) && $overrides[$key] !== null,
                'default' => $default,
            ];
        }

        return $out;
    }

    public function save(string $key, ?string $value): void
    {
        if (!array_key_exists($key, self::EDITABLE)) {
            throw new \InvalidArgumentException('Ajuste desconocido: ' . $key);
        }
        if ($value === null) {
            $this->repository->delete($key);
        } else {
            $this->repository->set($key, $value);
        }
        $this->overrides = null;
    }

    private function value(string $key): string
    {
        $overrides = $this->overrides();
        if (isset($overrides[$key])) {
            return $overrides[$key];
        }

        return $this->config->string(self::EDITABLE[$key]);
    }

    /**
     * @return array<string, string|null>
     */
    private function overrides(): array
    {
        if ($this->overrides === null) {
            try {
                $this->overrides = $this->repository->allValues();
            } catch (\PDOException) {
                $this->overrides = [];
            }
        }

        return $this->overrides;
    }

    /**
     * Límites que el frontend necesita para validar antes de subir.
     *
     * @return array<string, mixed>
     */
    public function clientLimits(): array
    {
        return [
            'maxFileBytes' => $this->maxFileBytes(),
            'singleMaxBytes' => $this->singleMaxBytes(),
            'blockedExtensions' => $this->blockedExtensions(),
            'zipMaxBytes' => $this->zipMaxBytes(),
            'zipMaxFiles' => $this->zipMaxFiles(),
        ];
    }
}
