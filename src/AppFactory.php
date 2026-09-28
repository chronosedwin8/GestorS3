<?php

declare(strict_types=1);

namespace App;

use App\Http\ErrorHandler;
use App\Support\Config;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Crea la aplicación Slim con middleware y rutas.
 */
final class AppFactory
{
    /**
     * @return App<ContainerInterface>
     */
    public static function create(ContainerInterface $container): App
    {
        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        /** @var Config $config */
        $config = $container->get(Config::class);
        $app->setBasePath($config->basePath());

        (require $config->string('app.root') . '/config/middleware.php')($app);
        (require $config->string('app.root') . '/config/routes.php')($app);

        $errorMiddleware = $app->addErrorMiddleware(!$config->isProduction(), false, false);
        $errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));

        return $app;
    }
}
