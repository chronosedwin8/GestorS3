<?php

declare(strict_types=1);

namespace Tests\Integration;

final class FolderPermissionsTest extends IntegrationTestCase
{
    public function testOwnerCreatesFolderAndSubfolders(): void
    {
        $owner = $this->createUser('owner@a.co');
        $folder = $this->createFolder($owner, 'Informes');

        $sub = $this->api('POST', "/api/folders/$folder/folders", ['path' => '2026/Enero']);
        self::assertSame(201, $sub->getStatusCode());
        // Idempotente
        $again = $this->api('POST', "/api/folders/$folder/folders", ['path' => '2026/Enero']);
        self::assertSame($this->json($sub)['data']['uuid'], $this->json($again)['data']['uuid']);
        // Duplicado explícito por nombre
        self::assertSame(409, $this->api('POST', "/api/folders/$folder/folders", ['name' => '2026'])->getStatusCode());

        $contents = $this->json($this->api('GET', "/api/folders/$folder"))['data'];
        self::assertSame('owner', $contents['permission']);
        self::assertSame(['2026'], array_column($contents['folders'], 'name'));

        $home = $this->json($this->api('GET', '/api/folders'))['data'];
        self::assertSame(['Informes'], array_column($home['mine'], 'name'));
    }

    public function testViewerCanReadButNotWrite(): void
    {
        $owner = $this->createUser('owner@a.co');
        $viewer = $this->createUser('lector@otra.org');
        $folder = $this->createFolder($owner);
        $file = $this->uploadSmall($folder, 'acta.txt');

        $invite = $this->api('POST', "/api/folders/$folder/members", ['emails' => 'lector@otra.org', 'permission' => 'viewer']);
        self::assertSame('added', $this->json($invite)['data']['results'][0]['status']);

        $this->actingAs($viewer);
        $contents = $this->api('GET', "/api/folders/$folder");
        self::assertSame(200, $contents->getStatusCode());
        $data = $this->json($contents)['data'];
        self::assertSame('viewer', $data['permission']);
        self::assertFalse($data['capabilities']['upload']);

        self::assertSame(403, $this->api('POST', "/api/folders/$folder/files/init", ['name' => 'x.txt', 'size' => 1])->getStatusCode());
        self::assertSame(403, $this->api('POST', "/api/folders/$folder/folders", ['name' => 'Nueva'])->getStatusCode());
        self::assertSame(403, $this->api('DELETE', '/api/files/' . $file['uuid'])->getStatusCode());
        self::assertSame(403, $this->api('PATCH', '/api/files/' . $file['uuid'], ['name' => 'otro.txt'])->getStatusCode());
        self::assertSame(403, $this->api('POST', "/api/folders/$folder/delete-items", ['fileUuids' => [$file['uuid']]])->getStatusCode());
        self::assertSame(403, $this->api('POST', "/api/folders/$folder/members", ['emails' => 'x@y.co', 'permission' => 'viewer'])->getStatusCode());
        self::assertSame(403, $this->api('POST', "/api/folders/$folder/share-links", [])->getStatusCode());

        // Pero sí puede descargar (302 directo a S3, sin pasar el archivo por PHP).
        $download = $this->request('GET', '/api/files/' . $file['uuid'] . '/download');
        self::assertSame(302, $download->getStatusCode());
        self::assertStringContainsString('bucket-de-pruebas', $download->getHeaderLine('Location'));
        self::assertStringContainsString('response-content-disposition=attachment', $download->getHeaderLine('Location'));

        // Y aparece en "Compartidas conmigo".
        $home = $this->json($this->api('GET', '/api/folders'))['data'];
        self::assertCount(1, $home['shared']);
    }

    public function testStrangerGets404(): void
    {
        $owner = $this->createUser('owner@a.co');
        $folder = $this->createFolder($owner);
        $file = $this->uploadSmall($folder, 'privado.txt');

        $this->actingAs($this->createUser('extrano@b.co'));
        self::assertSame(404, $this->api('GET', "/api/folders/$folder")->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/files/' . $file['uuid'] . '/download')->getStatusCode());
        self::assertSame(0, count($this->json($this->api('GET', '/api/search?q=privado'))['data']['files']));
    }

    public function testEditorPermissions(): void
    {
        $owner = $this->createUser('owner@a.co');
        $editor = $this->createUser('editor@b.co');
        $folder = $this->createFolder($owner);
        $this->api('POST', "/api/folders/$folder/members", ['emails' => 'editor@b.co', 'permission' => 'editor']);

        $this->actingAs($editor);
        $file = $this->uploadSmall($folder, 'nuevo.txt');
        self::assertSame(200, $this->api('PATCH', '/api/files/' . $file['uuid'], ['name' => 'renombrado.txt'])->getStatusCode());
        self::assertSame(201, $this->api('POST', "/api/folders/$folder/folders", ['name' => 'Sub'])->getStatusCode());
        // No puede borrar la carpeta raíz ni renombrarla, ni invitar editores.
        self::assertSame(403, $this->api('DELETE', "/api/folders/$folder")->getStatusCode());
        self::assertSame(403, $this->api('PATCH', "/api/folders/$folder", ['name' => 'Otro'])->getStatusCode());
        self::assertSame(403, $this->api('POST', "/api/folders/$folder/members", ['emails' => 'x@y.co', 'permission' => 'editor'])->getStatusCode());
        self::assertSame(200, $this->api('DELETE', '/api/files/' . $file['uuid'])->getStatusCode());
    }

    public function testOwnerManagesMembersAndDeletesFolder(): void
    {
        $owner = $this->createUser('owner@a.co');
        $member = $this->createUser('m@b.co');
        $folder = $this->createFolder($owner);
        $this->api('POST', "/api/folders/$folder/members", ['emails' => 'm@b.co', 'permission' => 'viewer']);
        $members = $this->json($this->api('GET', "/api/folders/$folder/members"))['data'];
        $memberUuid = $members['members'][1]['userUuid'];

        self::assertSame(200, $this->api('PATCH', "/api/folders/$folder/members/$memberUuid", ['permission' => 'editor'])->getStatusCode());
        self::assertSame(403, $this->api('DELETE', "/api/folders/$folder/members/" . $members['members'][0]['userUuid'])->getStatusCode());
        self::assertSame(200, $this->api('DELETE', "/api/folders/$folder/members/$memberUuid")->getStatusCode());

        $this->actingAs($member);
        self::assertSame(404, $this->api('GET', "/api/folders/$folder")->getStatusCode());

        $this->actingAs($owner);
        self::assertSame(200, $this->api('DELETE', "/api/folders/$folder")->getStatusCode());
        self::assertSame(404, $this->api('GET', "/api/folders/$folder")->getStatusCode());
    }
}
