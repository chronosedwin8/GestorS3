<?php

declare(strict_types=1);

/**
 * Configuración de la aplicación. Todo valor proviene de variables de entorno (.env).
 *
 * @return array<string, mixed>
 */

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
};

$bool = static fn (mixed $v): bool => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);

$appUrl = rtrim((string) $env('APP_URL', 'http://localhost'), '/');
$basePath = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?? ''), '/');

return [
    'app' => [
        'name' => (string) $env('APP_NAME', 'Compartir Archivos'),
        'env' => (string) $env('APP_ENV', 'production'),
        'url' => $appUrl,
        'base_path' => $basePath,
        'key' => (string) $env('APP_KEY', ''),
        'timezone' => (string) $env('APP_TIMEZONE', 'America/Bogota'),
        'locale' => (string) $env('APP_LOCALE', 'es'),
        'root' => dirname(__DIR__),
    ],
    'db' => [
        'host' => (string) $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        'name' => (string) $env('DB_NAME', 'fileshare'),
        'user' => (string) $env('DB_USER', 'root'),
        'pass' => (string) $env('DB_PASS', ''),
    ],
    's3' => [
        'key' => (string) $env('AWS_ACCESS_KEY_ID', ''),
        'secret' => (string) $env('AWS_SECRET_ACCESS_KEY', ''),
        'region' => (string) $env('AWS_REGION', 'us-east-1'),
        'bucket' => (string) $env('S3_BUCKET', ''),
        'prefix' => trim((string) $env('S3_PREFIX', ''), '/'),
        'endpoint' => (string) $env('S3_ENDPOINT', ''),
        'path_style' => $bool($env('S3_USE_PATH_STYLE', 'false')),
        'upload_ttl' => (int) $env('S3_PRESIGN_UPLOAD_TTL', 1800),
        'download_ttl' => (int) $env('S3_PRESIGN_DOWNLOAD_TTL', 600),
    ],
    'limits' => [
        'single_max_bytes' => (int) $env('UPLOAD_SINGLE_MAX_BYTES', 15728640),
        'part_size_bytes' => (int) $env('UPLOAD_PART_SIZE_BYTES', 8388608),
        'max_file_bytes' => (int) $env('UPLOAD_MAX_FILE_BYTES', 5368709120),
        'folder_max_bytes' => (int) $env('FOLDER_MAX_BYTES', 53687091200),
        'zip_max_bytes' => (int) $env('ZIP_MAX_BYTES', 5368709120),
        'zip_max_files' => (int) $env('ZIP_MAX_FILES', 2000),
        'blocked_extensions' => (string) $env('BLOCKED_EXTENSIONS', 'exe,bat,cmd,sh,msi,scr,js,vbs,ps1'),
    ],
    'mail' => [
        'dsn' => (string) $env('MAIL_DSN', 'null://null'),
        'from' => (string) $env('MAIL_FROM', 'Compartir Archivos <no-reply@example.com>'),
    ],
    'session' => [
        'lifetime_min' => (int) $env('SESSION_LIFETIME_MIN', 480),
        'magic_lifetime_min' => (int) $env('MAGIC_SESSION_LIFETIME_MIN', 120),
        'secure' => str_starts_with($appUrl, 'https://'),
        'path' => dirname(__DIR__) . '/storage/sessions',
    ],
    'log' => [
        'level' => (string) $env('LOG_LEVEL', 'info'),
        'path' => dirname(__DIR__) . '/storage/logs',
    ],
];
