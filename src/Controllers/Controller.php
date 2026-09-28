<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\UnauthenticatedException;
use App\Http\RequestHelper;
use App\Support\ApiResponse;
use App\Support\Config;
use App\Support\RequestContext;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Base de los controladores: solo HTTP (entrada/salida). La lógica vive en los servicios.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Twig $twig,
        protected readonly Config $config,
        protected readonly Session $session,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    protected function user(ServerRequestInterface $request): array
    {
        $user = $request->getAttribute('user');
        if (!is_array($user)) {
            throw new UnauthenticatedException();
        }

        return $user;
    }

    protected function ctx(ServerRequestInterface $request): RequestContext
    {
        return RequestContext::fromRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(ServerRequestInterface $request): array
    {
        return RequestHelper::body($request);
    }

    protected function query(ServerRequestInterface $request, string $key, string $default = ''): string
    {
        $value = $request->getQueryParams()[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->twig->render($response->withStatus($status), $template, $data);
    }

    protected function redirect(ResponseInterface $response, string $path, int $status = 302): ResponseInterface
    {
        $location = preg_match('#^https?://#', $path) === 1 ? $path : $this->config->basePath() . '/' . ltrim($path, '/');

        return $response->withStatus($status)->withHeader('Location', $location);
    }

    protected function ok(ResponseInterface $response, mixed $data = null, int $status = 200): ResponseInterface
    {
        return ApiResponse::ok($response, $data, $status);
    }
}
