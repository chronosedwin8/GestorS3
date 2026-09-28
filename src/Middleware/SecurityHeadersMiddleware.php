<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Config;
use App\Support\ViewContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cabeceras de seguridad (CSP, nosniff, Referrer-Policy...). HSTS se configura en Nginx.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $storage = 'https://*.amazonaws.com';
        $endpoint = $this->config->string('s3.endpoint');
        if ($endpoint !== '') {
            $storage .= ' ' . ViewContext::origin($endpoint);
        }
        // Alpine.js evalúa expresiones en tiempo de ejecución: requiere 'unsafe-eval'. No se permiten scripts inline.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: $storage",
            "media-src 'self' blob: $storage",
            "frame-src 'self' $storage",
            "connect-src 'self' $storage",
            "font-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];
        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }
        if (!$response->hasHeader('Cache-Control') && str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            $response = $response->withHeader('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
