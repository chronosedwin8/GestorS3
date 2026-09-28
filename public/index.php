<?php

declare(strict_types=1);

use App\AppFactory;

/** @var Psr\Container\ContainerInterface $container */
$container = require dirname(__DIR__) . '/src/bootstrap.php';

AppFactory::create($container)->run();
