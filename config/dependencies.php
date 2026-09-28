<?php

declare(strict_types=1);

use App\Services\MailService;
use App\Support\Config;
use App\Support\Token;
use App\Support\TwigExtension;
use Aws\S3\S3Client;

use function DI\autowire;
use function DI\get;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Twig\Environment;

return [
    Config::class => static fn (ContainerInterface $c): Config => new Config($c->get('settings')),

    PDO::class => static function (Config $config): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->string('db.host'),
            $config->int('db.port', 3306),
            $config->string('db.name'),
        );
        $pdo = new PDO($dsn, $config->string('db.user'), $config->string('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        return $pdo;
    },

    Token::class => static fn (Config $config): Token => new Token($config->string('app.key')),

    LoggerInterface::class => static function (Config $config): LoggerInterface {
        $logger = new Logger('app');
        $handler = new RotatingFileHandler($config->string('log.path') . '/app.log', 14, Level::fromName(ucfirst(strtolower($config->string('log.level', 'info')))));
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true));
        $logger->pushHandler($handler);

        return $logger;
    },

    'mailLogger' => static function (Config $config): LoggerInterface {
        $logger = new Logger('mail');
        $handler = new StreamHandler($config->string('log.path') . '/mail.log', Level::Debug);
        $handler->setFormatter(new LineFormatter("[%datetime%] %message%\n", 'Y-m-d H:i:s', true, true));
        $logger->pushHandler($handler);

        return $logger;
    },

    Twig::class => static function (Config $config, TwigExtension $extension): Twig {
        $root = $config->string('app.root');
        $twig = Twig::create($root . '/resources/views', [
            'cache' => $config->isProduction() ? $root . '/storage/cache/twig' : false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);
        $twig->addExtension($extension);

        return $twig;
    },

    Environment::class => static fn (Twig $twig): Environment => $twig->getEnvironment(),

    MailerInterface::class => static fn (Config $config): MailerInterface => new Mailer(Transport::fromDsn($config->string('mail.dsn', 'null://null'))),

    MailService::class => autowire()->constructorParameter('mailLog', get('mailLogger')),

    S3Client::class => static function (Config $config): S3Client {
        $options = [
            'version' => '2006-03-01',
            'region' => $config->string('s3.region', 'us-east-1'),
            'use_path_style_endpoint' => $config->bool('s3.path_style'),
            // Evita checksums CRC por defecto que invalidan URLs prefirmadas en navegadores.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'http' => ['connect_timeout' => 10, 'timeout' => 0],
        ];
        if ($config->string('s3.key') !== '') {
            $options['credentials'] = [
                'key' => $config->string('s3.key'),
                'secret' => $config->string('s3.secret'),
            ];
        }
        if ($config->string('s3.endpoint') !== '') {
            $options['endpoint'] = $config->string('s3.endpoint');
        }

        return new S3Client($options);
    },

    ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface => new ResponseFactory(),
];
