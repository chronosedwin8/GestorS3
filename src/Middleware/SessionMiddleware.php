<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\RequestHelper;
use App\Services\AuthService;
use App\Support\Config;
use App\Support\Session;
use App\Support\ViewContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Inicia la sesión, carga el usuario (si hay) y prepara el contexto de las vistas.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly ViewContext $view,
        private readonly Config $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();
        $this->auth->reset();
        $user = $this->auth->user();
        $path = RequestHelper::path($request, $this->config->basePath());
        $this->view->setUser($user);
        $this->view->setPath($path);

        // La API no modifica la sesión después de autenticar: liberar el bloqueo del archivo de sesión
        // permite que las peticiones en paralelo del cargador (firmas de partes, confirmaciones) no se serialicen.
        if ($this->session->isNative() && (str_starts_with($path, '/api/') || preg_match('#^/s/[^/]+/(api|files|zip-info)#', $path) === 1)) {
            $this->session->csrfToken();
            session_write_close();
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
