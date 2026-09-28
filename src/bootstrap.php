<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Construye el contenedor de dependencias (compartido por la web, la consola y las pruebas).
 */
return (static function (): ContainerInterface {
    $root = dirname(__DIR__);
    if (is_file($root . '/.env')) {
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }
    /** @var array<string, mixed> $settings */
    $settings = require $root . '/config/settings.php';

    // Internamente todo se maneja en UTC; las vistas convierten a APP_TIMEZONE.
    date_default_timezone_set('UTC');
    mb_internal_encoding('UTF-8');

    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions(['settings' => $settings]);
    $builder->addDefinitions(require $root . '/config/dependencies.php');

    return $builder->build();
})();
