<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Variable global "app" de Twig. Se lee en tiempo de render, por lo que refleja el estado de la petición actual.
 */
final class ViewContext
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private string $path = '/';

    /** @var list<array{type: string, message: string}>|null */
    private ?array $flashes = null;

    /** @var array<string, mixed>|null */
    private ?array $old = null;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    public function isAdmin(): bool
    {
        return ($this->user['role'] ?? '') === 'admin';
    }

    public function name(): string
    {
        return $this->config->string('app.name');
    }

    public function basePath(): string
    {
        return $this->config->basePath();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function csrf(): string
    {
        return $this->session->csrfToken();
    }

    public function isProduction(): bool
    {
        return $this->config->isProduction();
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function flashes(): array
    {
        return $this->flashes ??= $this->session->consumeFlashes();
    }

    public function old(string $key, string $default = ''): string
    {
        $this->old ??= $this->session->consumeOld();
        $value = $this->old[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Configuración que necesita el JavaScript del cliente.
     *
     * @return array<string, mixed>
     */
    public function jsConfig(): array
    {
        return [
            'basePath' => $this->config->basePath(),
            'csrf' => $this->session->csrfToken(),
            'appName' => $this->config->string('app.name'),
            'user' => $this->user === null ? null : [
                'uuid' => $this->user['uuid'],
                'name' => $this->user['name'],
                'email' => $this->user['email'],
                'isAdmin' => $this->isAdmin(),
            ],
            'limits' => $this->user === null ? null : $this->settings->clientLimits(),
            'appOrigin' => self::origin($this->config->string('app.url')),
        ];
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
