<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\AppException;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protección CSRF: token de sesión en el campo "_csrf" (formularios) o cabecera X-CSRF-Token (API).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->getHeaderLine('X-CSRF-Token');
            if ($token === '') {
                $body = $request->getParsedBody();
                $token = is_array($body) && is_string($body['_csrf'] ?? null) ? $body['_csrf'] : '';
            }
            if (!$this->session->validCsrf($token)) {
                throw new AppException('Tu sesión caducó o la página estuvo abierta mucho tiempo. Recarga la página e inténtalo de nuevo.', 'csrf_invalid', 403);
            }
        }

        return $handler->handle($request);
    }
}
