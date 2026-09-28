<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\UserRepository;

final class AuthFlowTest extends IntegrationTestCase
{
    public function testGuestIsRedirectedToLoginAndApiReturns401(): void
    {
        $page = $this->request('GET', '/folders/' . '11111111-1111-4111-8111-111111111111');
        self::assertSame(302, $page->getStatusCode());
        self::assertStringStartsWith('/login?next=', $page->getHeaderLine('Location'));

        $api = $this->api('GET', '/api/folders');
        self::assertSame(401, $api->getStatusCode());
        self::assertSame(['ok' => false, 'error' => ['code' => 'unauthenticated', 'message' => 'Tu sesión expiró. Vuelve a iniciar sesión.']], $this->json($api));
    }

    public function testLoginWithPassword(): void
    {
        $this->createUser('ana@entidad-a.gov.co');
        self::assertSame(200, $this->request('GET', '/login')->getStatusCode());

        $bad = $this->request('POST', '/login', ['email' => 'ana@entidad-a.gov.co', 'password' => 'incorrecta', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame(302, $bad->getStatusCode());
        self::assertStringStartsWith('/login', $bad->getHeaderLine('Location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);

        $ok = $this->request('POST', '/login', ['email' => 'ANA@entidad-a.gov.co', 'password' => 'contraseña-segura', 'next' => '/profile', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame('/profile', $ok->getHeaderLine('Location'));
        self::assertSame(200, $this->request('GET', '/profile')->getStatusCode());
    }

    public function testCsrfIsRequired(): void
    {
        $this->actingAs($this->createUser('ana@a.co'));
        $response = $this->request('POST', '/api/folders', ['name' => 'X'], ['X-CSRF-Token' => 'invalido', 'Accept' => 'application/json']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('csrf_invalid', $this->json($response)['error']['code']);
    }

    public function testDisabledUserCannotLogin(): void
    {
        $user = $this->createUser('baja@a.co');
        $this->container->get(UserRepository::class)->update((int) $user['id'], ['status' => 'disabled']);
        $this->request('POST', '/login', ['email' => 'baja@a.co', 'password' => 'contraseña-segura', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginIsRateLimited(): void
    {
        $this->createUser('ana@a.co');
        for ($i = 0; $i < 9; $i++) {
            $this->request('POST', '/login', ['email' => 'ana@a.co', 'password' => 'mal', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        }
        // Aun con la contraseña correcta, el correo queda bloqueado temporalmente.
        $this->request('POST', '/login', ['email' => 'ana@a.co', 'password' => 'contraseña-segura', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testMagicLinkIsSingleUseAndCreatesLimitedSession(): void
    {
        $this->createUser('externo@otra.org');
        $sent = $this->request('POST', '/magic-link', ['email' => 'externo@otra.org', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame(200, $sent->getStatusCode());
        $url = $this->lastMailUrl('/magic/');
        $path = (string) parse_url($url, PHP_URL_PATH);

        // GET solo muestra la confirmación (los escáneres de correo no consumen el enlace).
        self::assertStringContainsString('Entrar ahora', (string) $this->request('GET', $path)->getBody());
        self::assertArrayNotHasKey('user_id', $_SESSION);

        $login = $this->request('POST', $path, ['_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame('/', $login->getHeaderLine('Location'));
        self::assertTrue($_SESSION['limited']);

        // Sesión limitada: sin acceso a administración aunque fuera admin; y el enlace no se reutiliza.
        $_SESSION = [];
        $again = $this->request('POST', $path, ['_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertStringContainsString('/login', $again->getHeaderLine('Location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testMagicLinkDoesNotRevealUnknownEmails(): void
    {
        $response = $this->request('POST', '/magic-link', ['email' => 'nadie@x.co', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Revisa tu correo', (string) $response->getBody());
        self::assertCount(0, $this->mail->getRecords());
    }

    public function testPasswordReset(): void
    {
        $this->createUser('olvido@a.co');
        $this->request('POST', '/forgot-password', ['email' => 'olvido@a.co', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        $path = (string) parse_url($this->lastMailUrl('/reset-password/'), PHP_URL_PATH);
        self::assertStringContainsString('Crea una nueva contraseña', (string) $this->request('GET', $path)->getBody());

        $reset = $this->request('POST', $path, ['password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame('/', $reset->getHeaderLine('Location'));

        $_SESSION = [];
        $this->request('POST', '/login', ['email' => 'olvido@a.co', 'password' => 'nueva-clave-123', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertArrayHasKey('user_id', $_SESSION);
        // El enlace ya no sirve.
        self::assertStringContainsString('El enlace expiró', (string) $this->request('GET', $path)->getBody());
    }

    public function testAdminAreaRequiresAdminRole(): void
    {
        $this->actingAs($this->createUser('user@a.co'));
        self::assertSame(403, $this->request('GET', '/admin/users')->getStatusCode());

        $this->actingAs($this->createUser('admin@a.co', 'admin'));
        foreach (['users', 'entities', 'settings', 'activity', 'storage'] as $section) {
            self::assertSame(200, $this->request('GET', '/admin/' . $section)->getStatusCode(), $section);
        }
    }
}
