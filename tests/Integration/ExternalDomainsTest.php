<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\UserRepository;
use App\Services\SettingsService;

/**
 * Un usuario del colegio comparte con personas de Gmail, Hotmail u otras empresas: todas pueden ver
 * y descargar lo compartido, sin importar el dominio de su correo.
 */
final class ExternalDomainsTest extends IntegrationTestCase
{
    public function testAnyEmailDomainCanAccessWhatIsShared(): void
    {
        $this->container->get(SettingsService::class)->save('internal_domains', 'colegioaleman.edu.co');
        $docente = $this->createUser('docente@colegioaleman.edu.co');
        $folder = $this->createFolder($docente, 'Circulares');
        $file = $this->uploadSmall($folder, 'circular-01.pdf');

        $emails = ['padre.familia@gmail.com', 'acudiente@hotmail.com', 'contacto@proveedor-sas.com.co', 'colega@colegioaleman.edu.co'];
        $results = $this->json($this->api('POST', "/api/folders/$folder/members", ['emails' => implode(', ', $emails), 'permission' => 'viewer']))['data']['results'];
        self::assertSame(['invited', 'invited', 'invited', 'invited'], array_column($results, 'status'));

        foreach ($results as $i => $result) {
            $_SESSION = [];
            $this->request('POST', (string) parse_url($result['link'], PHP_URL_PATH), [
                'name' => 'Persona ' . $i, 'password' => 'clave-segura-' . $i, 'password_confirmation' => 'clave-segura-' . $i, '_csrf' => $this->csrf(),
            ], ['X-CSRF-Token' => '']);
            $user = $this->container->get(UserRepository::class)->findByEmail($emails[$i]);
            self::assertNotNull($user, $emails[$i]);

            // Ve la carpeta y descarga el archivo, sea cual sea su dominio.
            $contents = $this->json($this->api('GET', "/api/folders/$folder"))['data'];
            self::assertSame('viewer', $contents['permission'], $emails[$i]);
            self::assertSame(['circular-01.pdf'], array_column($contents['files'], 'name'));
            self::assertSame(302, $this->request('GET', '/api/files/' . $file['uuid'] . '/download')->getStatusCode(), $emails[$i]);
            self::assertCount(1, $this->json($this->api('GET', '/api/folders'))['data']['shared']);

            // Solo los del colegio pueden además crear carpetas propias.
            $expected = str_ends_with($emails[$i], '@colegioaleman.edu.co') ? 'user' : 'external';
            self::assertSame($expected, $user['role'], $emails[$i]);
        }
    }
}
