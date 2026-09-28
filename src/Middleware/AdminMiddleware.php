<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Solo administradores (y no desde una sesión limitada de enlace mágico).
 */
final class AdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
            throw new ForbiddenException('Esta sección es solo para administradores.');
        }
        if (($user['limited_session'] ?? false) === true) {
            throw new ForbiddenException('Para entrar a la administración inicia sesión con tu contraseña.');
        }

        return $handler->handle($request);
    }
}
