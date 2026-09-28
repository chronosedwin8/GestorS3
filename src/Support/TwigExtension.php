<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AccessService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Funciones y filtros propios para las plantillas.
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    /** @var array<string, string> */
    private array $assetVersions = [];

    public function __construct(
        private readonly Config $config,
        private readonly ViewContext $context,
    ) {
    }

    public function getGlobals(): array
    {
        return ['app' => $this->context];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', [$this, 'url']),
            new TwigFunction('asset', [$this, 'asset']),
            new TwigFunction('icon', [$this, 'icon'], ['is_safe' => ['html']]),
            new TwigFunction('json_script', [$this, 'jsonScript'], ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('bytes', [Bytes::class, 'human']),
            new TwigFilter('local_date', [$this, 'localDate']),
            new TwigFilter('iso', [Present::class, 'iso']),
            new TwigFilter('json_decode', static fn (?string $json): mixed => $json === null || $json === '' ? [] : (json_decode($json, true) ?? [])),
            new TwigFilter('perm_label', static fn (?string $p): string => AccessService::LABELS[$p ?? ''] ?? (string) $p),
        ];
    }

    public function url(string $path = '/'): string
    {
        return $this->config->basePath() . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if (!isset($this->assetVersions[$path])) {
            $file = $this->config->string('app.root') . '/public/assets/' . $path;
            $this->assetVersions[$path] = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
        }

        return $this->config->basePath() . '/assets/' . $path . '?v=' . $this->assetVersions[$path];
    }

    public function icon(string $name, string $class = 'h-5 w-5'): string
    {
        return sprintf(
            '<svg class="%s" aria-hidden="true" focusable="false"><use href="%s#%s"></use></svg>',
            htmlspecialchars($class, ENT_QUOTES),
            htmlspecialchars($this->asset('icons.svg'), ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
        );
    }

    public function jsonScript(mixed $data, string $id): string
    {
        return sprintf(
            '<script type="application/json" id="%s">%s</script>',
            htmlspecialchars($id, ENT_QUOTES),
            json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * Fecha UTC de la base de datos formateada en la zona horaria de la app.
     */
    public function localDate(?string $datetime, string $format = 'd/m/Y H:i'): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        try {
            $date = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));

            return $date->setTimezone(new \DateTimeZone($this->config->string('app.timezone', 'UTC')))->format($format);
        } catch (\Exception) {
            return $datetime;
        }
    }
}
