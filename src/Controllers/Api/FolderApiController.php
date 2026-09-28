<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\RequestHelper;
use App\Services\AuditService;
use App\Services\FolderService;
use App\Services\ZipService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class FolderApiController extends Controller
{
    public function __construct(
        Twig $twig,
        Config $config,
        Session $session,
        private readonly FolderService $folders,
        private readonly ZipService $zip,
        private readonly AuditService $audit,
    ) {
        parent::__construct($twig, $config, $session);
    }

    public function home(Request $request, Response $response): Response
    {
        return $this->ok($response, $this->folders->home($this->user($request)));
    }

    public function createRoot(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $description = RequestHelper::str($body, 'description');
        $folder = $this->folders->createRoot($this->user($request), RequestHelper::str($body, 'name'), $description !== '' ? $description : null, $this->ctx($request));

        return $this->ok($response, $folder, 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->folders->contents($this->user($request), $args['uuid']));
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);
        $name = array_key_exists('name', $body) ? RequestHelper::str($body, 'name') : null;
        $description = array_key_exists('description', $body) ? RequestHelper::str($body, 'description') : null;

        return $this->ok($response, $this->folders->update($this->user($request), $args['uuid'], $name, $description, $this->ctx($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->folders->delete($this->user($request), $args['uuid'], $this->ctx($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function createSubfolder(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);
        $path = RequestHelper::str($body, 'path');
        $isPath = $path !== '';
        $folder = $this->folders->createSubfolder(
            $this->user($request),
            $args['uuid'],
            $isPath ? $path : RequestHelper::str($body, 'name'),
            $this->ctx($request),
            !$isPath,
        );

        return $this->ok($response, $folder, 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteItems(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);

        return $this->ok($response, $this->folders->deleteItems(
            $this->user($request),
            $args['uuid'],
            RequestHelper::stringList($body, 'fileUuids'),
            RequestHelper::stringList($body, 'folderUuids'),
            $this->ctx($request),
        ));
    }

    /**
     * Tamaño total y número de archivos de un ZIP antes de descargarlo.
     *
     * @param array<string, string> $args
     */
    public function zipInfo(Request $request, Response $response, array $args): Response
    {
        [$folder] = $this->folders->getWithAccess($this->user($request), $args['uuid'], 'viewer');
        $body = $this->body($request);
        $plan = $this->zip->plan($folder, RequestHelper::stringList($body, 'fileUuids'), RequestHelper::stringList($body, 'folderUuids'));
        $this->zip->assertWithinLimits($plan);

        return $this->ok($response, ['name' => $plan['name'] . '.zip', 'totalBytes' => $plan['totalBytes'], 'count' => $plan['count']]);
    }

    /**
     * ZIP en streaming (formulario POST: el navegador gestiona la descarga).
     *
     * @param array<string, string> $args
     */
    public function downloadZip(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);
        [$folder] = $this->folders->getWithAccess($user, $args['uuid'], 'viewer');
        $body = $this->body($request);
        $plan = $this->zip->plan($folder, RequestHelper::stringList($body, 'fileUuids'), RequestHelper::stringList($body, 'folderUuids'));
        $this->zip->assertWithinLimits($plan);
        $this->audit->log('download_zip', (int) $user['id'], 'folder', (int) $folder['id'], [
            'name' => $folder['name'],
            'files' => $plan['count'],
            'bytes' => $plan['totalBytes'],
        ], $this->ctx($request));
        // Liberar el bloqueo de sesión para que el usuario pueda seguir navegando durante la descarga.
        session_write_close();
        $this->zip->stream($plan);

        return $response;
    }

    public function search(Request $request, Response $response): Response
    {
        return $this->ok($response, $this->folders->search($this->user($request), $this->query($request, 'q')));
    }
}
