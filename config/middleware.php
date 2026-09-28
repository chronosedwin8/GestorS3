<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\SessionMiddleware;
use Slim\App;

/**
 * Middleware global. Slim ejecuta en orden inverso al de registro (el último es el más externo):
 * SecurityHeaders -> Session -> Routing -> BodyParsing -> Csrf -> ruta
 */
return static function (App $app): void {
    $app->add(CsrfMiddleware::class);
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    $app->add(SessionMiddleware::class);
    $app->add(SecurityHeadersMiddleware::class);
};
