<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AppFactory;
use App\Services\AuthService;
use Aws\MockHandler;
use Aws\S3\S3Client;
use DI\Container;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Pruebas de extremo a extremo sobre la aplicación Slim completa (rutas, middleware, servicios y MySQL).
 * S3 se simula con Aws\MockHandler (las URLs prefirmadas se calculan localmente) y los correos se capturan.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Container $container;

    /** @var App<ContainerInterface|null> */
    protected App $app;

    protected MockHandler $s3;

    protected TestHandler $mail;

    protected function setUp(): void
    {
        if (!TEST_DB_AVAILABLE) {
            self::markTestSkipped('MySQL no disponible para pruebas de integración.');
        }
        $_SESSION = [];
        /** @var Container $container */
        $container = require dirname(__DIR__, 2) . '/src/bootstrap.php';
        $this->container = $container;

        $this->s3 = new MockHandler();
        $this->container->set(S3Client::class, new S3Client([
            'version' => '2006-03-01',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'AKIATESTTESTTEST', 'secret' => 'test-secret'],
            'handler' => $this->s3,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]));
        $this->mail = new TestHandler();
        $this->container->set('mailLogger', new Logger('mail', [$this->mail]));

        $pdo = $this->container->get(PDO::class);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['audit_log', 'share_links', 'upload_sessions', 'files', 'invitations', 'folder_members', 'folders', 'magic_links', 'password_resets', 'users', 'entities', 'settings', 'rate_limits'] as $table) {
            $pdo->exec("TRUNCATE TABLE $table");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->app = AppFactory::create($this->container);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path, [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit',
        ]);
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            $request = $request->withQueryParams($params);
        }
        if ($method !== 'GET' && !array_key_exists('X-CSRF-Token', $headers)) {
            $headers['X-CSRF-Token'] = $this->csrf();
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $path, ?array $body = null): ResponseInterface
    {
        return $this->request($method, $path, $body ?? ($method === 'GET' ? null : []), ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, 'La respuesta no es JSON: ' . substr((string) $response->getBody(), 0, 300));

        return $data;
    }

    protected function csrf(): string
    {
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['_csrf'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function createUser(string $email, string $role = 'user', string $password = 'contraseña-segura'): array
    {
        return $this->container->get(AuthService::class)->createUser($email, ucfirst(strtok($email, '@') ?: 'Usuario'), $password, $role);
    }

    /**
     * Simula un navegador con la sesión iniciada para $user.
     *
     * @param array<string, mixed> $user
     */
    protected function actingAs(array $user): void
    {
        $_SESSION = ['user_id' => (int) $user['id'], 'last_activity' => time(), 'auth_at' => time(), 'limited' => false, '_csrf' => bin2hex(random_bytes(16))];
    }

    /**
     * Crea una carpeta raíz como $owner y devuelve su uuid.
     *
     * @param array<string, mixed> $owner
     */
    protected function createFolder(array $owner, string $name = 'Proyecto'): string
    {
        $this->actingAs($owner);
        $response = $this->api('POST', '/api/folders', ['name' => $name]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['data']['uuid'];
    }

    /**
     * Sube un archivo pequeño completo (init + confirm con HeadObject simulado).
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function uploadSmall(string $folderUuid, string $name, int $size = 100, array $extra = []): array
    {
        $init = $this->api('POST', "/api/folders/$folderUuid/files/init", ['name' => $name, 'size' => $size, 'mime' => 'text/plain'] + $extra);
        self::assertSame(201, $init->getStatusCode(), (string) $init->getBody());
        $data = $this->json($init)['data'];
        if ($data['mode'] === 'skipped') {
            return $data;
        }
        $this->s3->append(new \Aws\Result(['ContentLength' => $size, 'ETag' => '"etag-' . $name . '"', 'ContentType' => 'text/plain']));
        $confirm = $this->api('POST', '/api/files/' . $data['fileUuid'] . '/confirm');
        self::assertSame(200, $confirm->getStatusCode(), (string) $confirm->getBody());

        return $this->json($confirm)['data'] + ['fileUuid' => $data['fileUuid']];
    }

    protected function lastMailUrl(string $pathContains): string
    {
        foreach (array_reverse($this->mail->getRecords()) as $record) {
            if (preg_match('#https?://\S*' . preg_quote($pathContains, '#') . '\S+#', $record->message, $m)) {
                return rtrim($m[0], '.');
            }
        }
        self::fail('No se encontró un correo con ' . $pathContains);
    }
}
