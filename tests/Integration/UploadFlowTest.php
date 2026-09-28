<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\SettingsService;
use Aws\Result;

final class UploadFlowTest extends IntegrationTestCase
{
    public function testSingleUploadReturnsPresignedPut(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $init = $this->json($this->api('POST', "/api/folders/$folder/files/init", ['name' => 'Informe año.pdf', 'size' => 2048, 'mime' => 'application/pdf']))['data'];

        self::assertSame('single', $init['mode']);
        self::assertStringContainsString('bucket-de-pruebas', $init['url']);
        self::assertStringContainsString('X-Amz-Signature=', $init['url']);
        self::assertStringNotContainsString('Informe', (string) parse_url($init['url'], PHP_URL_PATH), 'La clave S3 no debe contener el nombre del usuario');
        self::assertSame('application/pdf', $init['headers']['Content-Type']);

        $this->s3->append(new Result(['ContentLength' => 2048, 'ETag' => '"abc"']));
        $file = $this->json($this->api('POST', '/api/files/' . $init['fileUuid'] . '/confirm'))['data'];
        self::assertSame('Informe año.pdf', $file['name']);
        self::assertSame('pdf', $file['kind']);
        self::assertTrue($file['previewable']);

        $preview = $this->json($this->api('GET', '/api/files/' . $file['uuid'] . '/preview'))['data'];
        self::assertSame('pdf', $preview['kind']);
        self::assertStringContainsString('response-content-disposition=inline', $preview['url']);
    }

    public function testConfirmFailsWhenSizeDiffers(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $init = $this->json($this->api('POST', "/api/folders/$folder/files/init", ['name' => 'a.txt', 'size' => 100]))['data'];
        $this->s3->append(new Result(['ContentLength' => 50]), new Result([]));
        $response = $this->api('POST', '/api/files/' . $init['fileUuid'] . '/confirm');
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('size_mismatch', $this->json($response)['error']['code']);
    }

    public function testNameConflictResolutions(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $this->uploadSmall($folder, 'acta.docx');

        $conflict = $this->api('POST', "/api/folders/$folder/files/init", ['name' => 'acta.docx', 'size' => 10]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('name_conflict', $this->json($conflict)['error']['code']);

        self::assertSame('skipped', $this->uploadSmall($folder, 'acta.docx', 10, ['onConflict' => 'skip'])['mode']);
        self::assertSame('acta (2).docx', $this->uploadSmall($folder, 'acta.docx', 10, ['onConflict' => 'keep_both'])['name']);
        $replaced = $this->uploadSmall($folder, 'acta.docx', 30, ['onConflict' => 'replace']);
        self::assertSame(2, $replaced['version']);

        $files = $this->json($this->api('GET', "/api/folders/$folder"))['data']['files'];
        self::assertSame(['acta (2).docx', 'acta.docx'], array_column($files, 'name'));
        $details = $this->json($this->api('GET', '/api/files/' . $replaced['uuid']))['data'];
        self::assertCount(2, $details['versions']);
        // La versión anterior se puede descargar.
        self::assertSame(302, $this->request('GET', '/api/files/' . $details['versions'][1]['uuid'] . '/download')->getStatusCode());
    }

    public function testFolderUploadRecreatesStructure(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $this->uploadSmall($folder, 'uno.txt', 10, ['relativePath' => 'Fotos/2026/Viaje/uno.txt']);
        $this->uploadSmall($folder, 'dos.txt', 10, ['relativePath' => 'Fotos/2026/dos.txt']);

        $root = $this->json($this->api('GET', "/api/folders/$folder"))['data'];
        self::assertSame(['Fotos'], array_column($root['folders'], 'name'));
        self::assertSame(2, $root['folders'][0]['filesCount']);
    }

    public function testMultipartUploadWithResume(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $size = 20 * 1024 * 1024;
        $this->s3->append(new Result(['UploadId' => 'upload-123']));
        $init = $this->json($this->api('POST', "/api/folders/$folder/files/init", ['name' => 'video.mp4', 'size' => $size, 'mime' => 'video/mp4']))['data'];
        self::assertSame('multipart', $init['mode']);
        self::assertSame(8 * 1024 * 1024, $init['partSize']);
        self::assertSame(3, $init['totalParts']);

        $signed = $this->json($this->api('POST', '/api/uploads/' . $init['uploadSessionUuid'] . '/parts/sign', ['partNumbers' => [1, 2, 3]]))['data'];
        self::assertCount(3, $signed['parts']);
        self::assertStringContainsString('uploadId=upload-123', $signed['parts'][0]['url']);
        self::assertStringContainsString('partNumber=1', $signed['parts'][0]['url']);
        self::assertSame(422, $this->api('POST', '/api/uploads/' . $init['uploadSessionUuid'] . '/parts/sign', ['partNumbers' => [4]])->getStatusCode());

        // Reanudación: el servidor consulta ListParts.
        $this->s3->append(new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"', 'Size' => 8388608]], 'IsTruncated' => false]));
        $status = $this->json($this->api('GET', '/api/uploads/' . $init['uploadSessionUuid']))['data'];
        self::assertSame(1, $status['parts'][0]['partNumber']);

        $this->s3->append(new Result(['ETag' => '"final-3"']), new Result(['ContentLength' => $size, 'ETag' => '"final-3"']));
        $complete = $this->api('POST', '/api/uploads/' . $init['uploadSessionUuid'] . '/complete', ['parts' => [
            ['partNumber' => 1, 'etag' => '"e1"'], ['partNumber' => 2, 'etag' => '"e2"'], ['partNumber' => 3, 'etag' => '"e3"'],
        ]]);
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());
        self::assertSame($size, $this->json($complete)['data']['size']);
        // Una sesión completada no admite más firmas.
        self::assertSame(409, $this->api('POST', '/api/uploads/' . $init['uploadSessionUuid'] . '/parts/sign', ['partNumbers' => [1]])->getStatusCode());
    }

    public function testCancelMultipart(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $this->s3->append(new Result(['UploadId' => 'u-1']));
        $init = $this->json($this->api('POST', "/api/folders/$folder/files/init", ['name' => 'big.iso', 'size' => 50 * 1024 * 1024]))['data'];
        $this->s3->append(new Result([]));
        self::assertSame(200, $this->api('DELETE', '/api/uploads/' . $init['uploadSessionUuid'])->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/uploads/' . $init['uploadSessionUuid'] . '/complete', ['parts' => []])->getStatusCode());
        self::assertSame([], $this->json($this->api('GET', "/api/folders/$folder"))['data']['files']);
    }

    public function testLimitsAndBlockedExtensions(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $blocked = $this->api('POST', "/api/folders/$folder/files/init", ['name' => 'setup.EXE', 'size' => 10]);
        self::assertSame('blocked_extension', $this->json($blocked)['error']['code']);

        $tooBig = $this->api('POST', "/api/folders/$folder/files/init", ['name' => 'x.bin', 'size' => 6 * 1024 ** 3]);
        self::assertSame(413, $tooBig->getStatusCode());
        self::assertStringContainsString('5 GB', $this->json($tooBig)['error']['message']);

        $this->container->get(SettingsService::class)->save('folder_max_bytes', '1000');
        $this->uploadSmall($folder, 'a.txt', 900);
        $quota = $this->api('POST', "/api/folders/$folder/files/init", ['name' => 'b.txt', 'size' => 200]);
        self::assertSame('folder_quota_exceeded', $this->json($quota)['error']['code']);

        // El administrador puede vaciar la lista negra.
        $this->container->get(SettingsService::class)->save('blocked_extensions', '');
        $this->container->get(SettingsService::class)->save('folder_max_bytes', null);
        self::assertSame(201, $this->api('POST', "/api/folders/$folder/files/init", ['name' => 'setup.exe', 'size' => 10])->getStatusCode());
    }

    public function testZipInfoAndLimits(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $a = $this->uploadSmall($folder, 'a.txt', 100);
        $this->uploadSmall($folder, 'b.txt', 200, ['relativePath' => 'Sub/b.txt']);

        $all = $this->json($this->api('POST', "/api/folders/$folder/zip-info", []))['data'];
        self::assertSame(2, $all['count']);
        self::assertSame(300, $all['totalBytes']);
        self::assertSame('Proyecto.zip', $all['name']);

        $selection = $this->json($this->api('POST', "/api/folders/$folder/zip-info", ['fileUuids' => [$a['uuid']]]))['data'];
        self::assertSame(1, $selection['count']);

        $this->container->get(SettingsService::class)->save('zip_max_files', '1');
        $tooMany = $this->api('POST', "/api/folders/$folder/zip-info", []);
        self::assertSame('zip_too_large', $this->json($tooMany)['error']['code']);
    }

    public function testUploadsAreAudited(): void
    {
        $folder = $this->createFolder($this->createUser('owner@a.co'));
        $file = $this->uploadSmall($folder, 'a.txt');
        $this->request('GET', '/api/files/' . $file['uuid'] . '/download');
        $details = $this->json($this->api('GET', '/api/files/' . $file['uuid']))['data'];
        self::assertSame('download', $details['downloads'][0]['action']);
    }
}
