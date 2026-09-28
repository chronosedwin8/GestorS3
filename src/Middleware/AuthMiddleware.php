<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\RequestHelper;
use App\Support\ApiResponse;
use App\Support\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exige usuario autenticado. HTML: redirige al login. API: 401 JSON.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly Config $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('user') !== null) {
            return $handler->handle($request);
        }
        $base = $this->config->basePath();
        if (RequestHelper::wantsJson($request, $base)) {
            return ApiResponse::error($this->responses->createResponse(), 'unauthenticated', 'Tu sesión expiró. Vuelve a iniciar sesión.', 401);
        }
        $path = RequestHelper::path($request, $base);
        $query = $request->getUri()->getQuery();
        $next = $path . ($query !== '' ? '?' . $query : '');
        $location = $base . '/login' . ($next !== '/' ? '?next=' . rawurlencode($next) : '');

        return $this->responses->createResponse(302)->withHeader('Location', $location);
    }
}
