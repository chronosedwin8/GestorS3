<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Las pruebas de integración usan una base de datos propia (DB_NAME en phpunit.xml).
// Se migra una sola vez al inicio si MySQL está disponible.
$dbAvailable = false;
try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $settings = require dirname(__DIR__) . '/config/settings.php';
    $db = $settings['db'];
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', str_replace('`', '', $db['name'])));
    $app = new Phinx\Console\PhinxApplication();
    $app->setAutoExit(false);
    $code = $app->run(
        new Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--configuration' => dirname(__DIR__) . '/phinx.php', '--environment' => 'app']),
        new Symfony\Component\Console\Output\NullOutput(),
    );
    $dbAvailable = $code === 0;
} catch (Throwable $e) {
    fwrite(STDERR, "Aviso: MySQL no disponible para pruebas de integración ({$e->getMessage()}).\n");
}
define('TEST_DB_AVAILABLE', $dbAvailable);
