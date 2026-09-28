<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\UserRepository;

final class SharingTest extends IntegrationTestCase
{
    public function testInvitationCreatesAccountFromAnyDomain(): void
    {
        $owner = $this->createUser('owner@a.co');
        $folder = $this->createFolder($owner, 'Contratos');

        $result = $this->json($this->api('POST', "/api/folders/$folder/members", ['emails' => 'Nueva.Persona@Proveedor-Externo.io, correo-invalido', 'permission' => 'viewer']))['data']['results'];
        self::assertSame('invited', $result[0]['status']);
        self::assertSame('error', $result[1]['status']);
        // Sin SMTP el enlace se entrega a quien invita para compartirlo manualmente.
        $path = (string) parse_url($result[0]['link'], PHP_URL_PATH);

        $_SESSION = [];
        $page = (string) $this->request('GET', $path)->getBody();
        self::assertStringContainsString('Contratos', $page);
        self::assertStringContainsString('nueva.persona@proveedor-externo.io', $page);

        $accept = $this->request('POST', $path, [
            'name' => 'Nueva Persona',
            'entity' => 'Proveedor Externo S.A.S.',
            'password' => 'mi-clave-segura',
            'password_confirmation' => 'mi-clave-segura',
            '_csrf' => $this->csrf(),
        ], ['X-CSRF-Token' => '']);
        self::assertSame("/folders/$folder", $accept->getHeaderLine('Location'));

        $user = $this->container->get(UserRepository::class)->findByEmail('nueva.persona@proveedor-externo.io');
        self::assertNotNull($user);
        self::assertSame('Proveedor Externo S.A.S.', $user['entity_name']);
        self::assertNotNull($user['email_verified_at']);
        self::assertSame('viewer', $this->json($this->api('GET', "/api/folders/$folder"))['data']['permission']);

        // La invitación no se puede reutilizar.
        $_SESSION = [];
        self::assertSame(404, $this->request('GET', $path)->getStatusCode());
    }

    public function testPublicLinkWithPasswordAndLimits(): void
    {
        $owner = $this->createUser('owner@a.co');
        $folder = $this->createFolder($owner);
        $file = $this->uploadSmall($folder, 'publico.txt');
        $sub = $this->json($this->api('POST', "/api/folders/$folder/folders", ['name' => 'Interna']))['data']['uuid'];

        $link = $this->json($this->api('POST', "/api/folders/$folder/share-links", ['password' => 'clave', 'expiresInDays' => 7, 'maxDownloads' => 1]))['data'];
        self::assertTrue($link['active']);
        self::assertTrue($link['hasPassword']);
        $path = (string) parse_url($link['url'], PHP_URL_PATH);
        // El dueño puede volver a ver el mismo enlace.
        self::assertSame($link['url'], $this->json($this->api('GET', "/api/folders/$folder/share-links"))['data']['links'][0]['url']);

        $_SESSION = [];
        self::assertStringContainsString('protegido', (string) $this->request('GET', $path)->getBody());
        self::assertSame(403, $this->request('GET', "$path/files/{$file['uuid']}/download")->getStatusCode());

        $this->request('POST', "$path/unlock", ['password' => 'mala', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertStringContainsString('protegido', (string) $this->request('GET', $path)->getBody());
        $this->request('POST', "$path/unlock", ['password' => 'clave', '_csrf' => $this->csrf()], ['X-CSRF-Token' => '']);
        self::assertStringContainsString('explorer-initial', (string) $this->request('GET', $path)->getBody());

        $contents = $this->json($this->request('GET', "$path/api/folders/$sub"))['data'];
        self::assertSame('Interna', $contents['folder']['name']);
        self::assertFalse($contents['capabilities']['upload']);

        self::assertSame(302, $this->request('GET', "$path/files/{$file['uuid']}/download")->getStatusCode());
        // Límite de 1 descarga alcanzado.
        self::assertSame(403, $this->request('GET', "$path/files/{$file['uuid']}/download")->getStatusCode());

        // Revocar
        $this->actingAs($owner);
        self::assertSame(200, $this->api('DELETE', '/api/share-links/' . $link['uuid'])->getStatusCode());
        $_SESSION = [];
        self::assertStringContainsString('desactivado', (string) $this->request('GET', $path)->getBody());
    }

    public function testPublicLinkCannotEscapeItsFolder(): void
    {
        $owner = $this->createUser('owner@a.co');
        $folder = $this->createFolder($owner);
        $sub = $this->json($this->api('POST', "/api/folders/$folder/folders", ['name' => 'Compartida']))['data']['uuid'];
        $secret = $this->uploadSmall($folder, 'secreto.txt');
        $link = $this->json($this->api('POST', "/api/folders/$sub/share-links", []))['data'];
        $path = (string) parse_url($link['url'], PHP_URL_PATH);

        $_SESSION = [];
        self::assertSame(404, $this->request('GET', "$path/api/folders/$folder")->getStatusCode());
        self::assertSame(404, $this->request('GET', "$path/files/{$secret['uuid']}/download")->getStatusCode());
        self::assertSame(404, $this->request('GET', '/s/token-que-no-existe-1234567890')->getStatusCode());
    }

    public function testSearchFindsAccessibleItems(): void
    {
        $owner = $this->createUser('owner@a.co');
        $folder = $this->createFolder($owner, 'Presupuesto');
        $this->uploadSmall($folder, 'Presupuesto final.xlsx');
        $results = $this->json($this->api('GET', '/api/search?q=presu'))['data'];
        self::assertCount(1, $results['folders']);
        self::assertCount(1, $results['files']);
        self::assertSame('sheet', $results['files'][0]['kind']);
    }
}
