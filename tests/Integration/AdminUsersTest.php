<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\UserRepository;
use App\Services\SettingsService;

/**
 * El administrador crea usuarios de la institución que suben y comparten con personas externas.
 */
final class AdminUsersTest extends IntegrationTestCase
{
    public function testAdminCreatesInternalUserWhoSharesWithExternalPerson(): void
    {
        $admin = $this->createUser('admin@institucion.edu.co', 'admin');
        $this->actingAs($admin);

        // 1. El administrador crea la cuenta con contraseña propia.
        $create = $this->request('POST', '/admin/users', [
            'name' => 'Laura Gómez',
            'email' => 'laura@institucion.edu.co',
            'role' => 'user',
            'password' => 'clave-de-laura',
            '_csrf' => $this->csrf(),
        ], ['X-CSRF-Token' => '']);
        self::assertSame('/admin/users', $create->getHeaderLine('Location'));
        $laura = $this->container->get(UserRepository::class)->findByEmail('laura@institucion.edu.co');
        self::assertNotNull($laura);
        self::assertSame('user', $laura['role']);
        self::assertStringContainsString('Laura Gómez', (string) $this->request('GET', '/admin/users')->getBody());

        // 2. Laura entra con esa contraseña, crea una carpeta y sube un archivo.
        $_SESSION = [];
        $this->request('POST', '/login', ['email' => 'laura@institucion.edu.co', 'password' => 'clave-de-laura', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame((int) $laura['id'], $_SESSION['user_id']);
        $folder = $this->json($this->api('POST', '/api/folders', ['name' => 'Convenio 2026']))['data']['uuid'];
        $file = $this->uploadSmall($folder, 'convenio.pdf');

        // 3. Comparte con alguien de otra organización, que aún no tiene cuenta.
        $result = $this->json($this->api('POST', "/api/folders/$folder/members", ['emails' => 'socio@empresa-aliada.com', 'permission' => 'editor']))['data']['results'][0];
        self::assertSame('invited', $result['status']);
        $path = (string) parse_url($result['link'], PHP_URL_PATH);

        // 4. La persona externa acepta la invitación: su cuenta es "externa".
        $_SESSION = [];
        $this->request('POST', $path, ['name' => 'Socio Externo', 'password' => 'clave-socio-1', 'password_confirmation' => 'clave-socio-1', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        $socio = $this->container->get(UserRepository::class)->findByEmail('socio@empresa-aliada.com');
        self::assertNotNull($socio);
        self::assertSame('external', $socio['role']);

        // Trabaja en la carpeta compartida (como editor puede subir y descargar)...
        $contents = $this->json($this->api('GET', "/api/folders/$folder"))['data'];
        self::assertSame('editor', $contents['permission']);
        $this->uploadSmall($folder, 'respuesta.docx');
        self::assertSame(302, $this->request('GET', '/api/files/' . $file['uuid'] . '/download')->getStatusCode());
        // ...pero no puede crear carpetas propias.
        $denied = $this->api('POST', '/api/folders', ['name' => 'Mía']);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('external_account', $this->json($denied)['error']['code']);
        self::assertStringNotContainsString('Nueva carpeta</button>', (string) $this->request('GET', '/')->getBody());
    }

    public function testCreateUserWithGeneratedPasswordAndAccessLink(): void
    {
        $this->actingAs($this->createUser('admin@institucion.edu.co', 'admin'));
        $this->request('POST', '/admin/users', [
            'name' => 'Pedro Ruiz',
            'email' => 'pedro@otra-entidad.org',
            'role' => 'external',
            'send_welcome' => '1',
            '_csrf' => $this->csrf(),
        ], ['X-CSRF-Token' => '']);
        $page = (string) $this->request('GET', '/admin/users')->getBody();
        self::assertStringContainsString('Cuenta creada', $page);
        self::assertStringContainsString('Contraseña temporal', $page);
        self::assertMatchesRegularExpression('/value="[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}"/', $page);

        // El correo de bienvenida lleva un enlace para definir la contraseña.
        $link = (string) parse_url($this->lastMailUrl('/reset-password/'), PHP_URL_PATH);
        $_SESSION = [];
        $this->request('POST', $link, ['password' => 'mi-propia-clave', 'password_confirmation' => 'mi-propia-clave', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        $_SESSION = [];
        $this->request('POST', '/login', ['email' => 'pedro@otra-entidad.org', 'password' => 'mi-propia-clave', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertArrayHasKey('user_id', $_SESSION);
    }

    public function testValidationAndDuplicateEmail(): void
    {
        $this->actingAs($this->createUser('admin@institucion.edu.co', 'admin'));
        $this->createUser('ya@existe.com');
        $response = $this->request('POST', '/admin/users', ['name' => 'X', 'email' => 'ya@existe.com', 'role' => 'user', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame('/admin/users?crear=1', $response->getHeaderLine('Location'));
        self::assertSame('Ya existe una cuenta con ese correo.', $_SESSION['_flash'][0]['message']);
    }

    public function testAdminCanResendAccessLink(): void
    {
        $this->actingAs($this->createUser('admin@institucion.edu.co', 'admin'));
        $user = $this->createUser('olvidadizo@x.org');
        $this->request('POST', '/admin/users/' . $user['uuid'] . '/access-link', ['_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertStringContainsString('/reset-password/', $this->lastMailUrl('/reset-password/'));
        self::assertStringContainsString('Enlace de acceso generado', (string) $this->request('GET', '/admin/users')->getBody());
    }

    public function testNonAdminCannotCreateUsers(): void
    {
        $this->actingAs($this->createUser('normal@institucion.edu.co'));
        self::assertSame(403, $this->request('POST', '/admin/users', ['name' => 'X', 'email' => 'x@y.co', 'role' => 'admin', '_csrf' => $this->csrf()], ['X-CSRF-Token' => ''])->getStatusCode());
    }

    public function testInternalDomainsBecomeInstitutionUsers(): void
    {
        $settings = $this->container->get(SettingsService::class);
        $settings->save('internal_domains', 'institucion.edu.co');
        self::assertSame('user', $settings->roleForEmail('ana@institucion.edu.co'));
        self::assertSame('user', $settings->roleForEmail('ana@sede.institucion.edu.co'));
        self::assertSame('external', $settings->roleForEmail('ana@otrainstitucion.edu.co'));

        $owner = $this->createUser('dueno@institucion.edu.co');
        $folder = $this->createFolder($owner);
        $result = $this->json($this->api('POST', "/api/folders/$folder/members", ['emails' => 'colega@institucion.edu.co', 'permission' => 'viewer']))['data']['results'][0];
        $_SESSION = [];
        $this->request('POST', (string) parse_url($result['link'], PHP_URL_PATH), ['name' => 'Colega', 'password' => 'clave-colega-1', 'password_confirmation' => 'clave-colega-1', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertSame('user', $this->container->get(UserRepository::class)->findByEmail('colega@institucion.edu.co')['role'] ?? null);
    }
}
