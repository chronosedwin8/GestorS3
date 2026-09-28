<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$settings = require __DIR__ . '/config/settings.php';
$db = $settings['db'];

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'app',
        'app' => [
            'adapter' => 'mysql',
            'host' => $db['host'],
            'name' => $db['name'],
            'user' => $db['user'],
            'pass' => $db['pass'],
            'port' => $db['port'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
