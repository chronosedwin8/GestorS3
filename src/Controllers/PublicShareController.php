<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Http\RequestHelper;
use App\Services\AuditService;
use App\Services\FileService;
use App\Services\FolderService;
use App\Services\ShareLinkService;
use App\Services\ZipService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Acceso de solo lectura mediante enlace público (sin cuenta).
 */
final class PublicShareController extends Controller
{
    public function __construct(
        Twig $twig,
        Config $config,
        Session $session,
        private readonly ShareLinkService $links,
        private readonly FolderService $folders,
        private readonly FileService $files,
        private readonly ZipService $zip,
        private readonly AuditService $audit,
    ) {
        parent::__construct($twig, $config, $session);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $link = $this->links->resolve($args['token']);
        } catch (AppException $e) {
            return $this->render($response, 'share/unavailable.html.twig', ['message' => $e->getMessage()], $e->httpStatus());
        }
        if (!$this->links->isUnlocked($link)) {
            return $this->render($response, 'share/password.html.twig', ['token' => $args['token'], 'folderName' => $link['folder_name']]);
        }
        $folder = $this->links->folderInLink($link, $this->query($request, 'folder'));
        $contents = $this->folders->describeContents($folder, 'viewer', (int) $link['folder_id']);
        $this->audit->log('share_link_view', null, 'folder', (int) $link['folder_id'], [], $this->ctx($request), (int) $link['id']);

        return $this->render($response, 'share/explorer.html.twig', [
            'initial' => ['view' => 'folder', 'folder' => $this->publicContents($contents)],
            'token' => $args['token'],
            'link' => $link,
            'pageTitle' => $link['folder_name'],
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function unlock(Request $request, Response $response, array $args): Response
    {
        try {
            $link = $this->links->resolve($args['token']);
            $this->links->unlock($link, RequestHelper::str($this->body($request), 'password'), $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/s/' . rawurlencode($args['token']));
    }

    /**
     * @param array<string, string> $args
     */
    public function contents(Request $request, Response $response, array $args): Response
    {
        $link = $this->unlockedLink($args['token']);
        $folder = $this->links->folderInLink($link, $args['uuid'] ?? null);

        return $this->ok($response, $this->publicContents($this->folders->describeContents($folder, 'viewer', (int) $link['folder_id'])));
    }

    /**
     * @param array<string, string> $args
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $link = $this->unlockedLink($args['token']);
        $file = $this->links->fileInLink($link, $args['uuid']);
        $this->links->registerDownload($link, 'download', 'file', (int) $file['id'], ['name' => $file['name']], $this->ctx($request));

        return $response->withStatus(302)
            ->withHeader('Location', $this->files->presignedDownload($file))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $args
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        $link = $this->unlockedLink($args['token']);
        $file = $this->links->fileInLink($link, $args['uuid']);
        $info = $this->files->previewInfo($file);
        $this->audit->log('preview', null, 'file', (int) $file['id'], ['name' => $file['name']], $this->ctx($request), (int) $link['id']);

        return $this->ok($response, $info);
    }

    /**
     * @param array<string, string> $args
     */
    public function zipInfo(Request $request, Response $response, array $args): Response
    {
        $link = $this->unlockedLink($args['token']);
        $body = $this->body($request);
        $folder = $this->links->folderInLink($link, RequestHelper::str($body, 'folderUuid'));
        $plan = $this->zip->plan($folder, RequestHelper::stringList($body, 'fileUuids'), RequestHelper::stringList($body, 'folderUuids'));
        $this->zip->assertWithinLimits($plan);

        return $this->ok($response, ['name' => $plan['name'] . '.zip', 'totalBytes' => $plan['totalBytes'], 'count' => $plan['count']]);
    }

    /**
     * @param array<string, string> $args
     */
    public function downloadZip(Request $request, Response $response, array $args): Response
    {
        $link = $this->unlockedLink($args['token']);
        $body = $this->body($request);
        $folder = $this->links->folderInLink($link, RequestHelper::str($body, 'folderUuid'));
        $plan = $this->zip->plan($folder, RequestHelper::stringList($body, 'fileUuids'), RequestHelper::stringList($body, 'folderUuids'));
        $this->zip->assertWithinLimits($plan);
        $this->links->registerDownload($link, 'download_zip', 'folder', (int) $folder['id'], ['name' => $folder['name'], 'files' => $plan['count'], 'bytes' => $plan['totalBytes']], $this->ctx($request));
        session_write_close();
        $this->zip->stream($plan);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function unlockedLink(string $token): array
    {
        $link = $this->links->resolve($token);
        $this->links->requireUnlocked($link);

        return $link;
    }

    /**
     * Quita datos que un visitante anónimo no necesita.
     *
     * @param array<string, mixed> $contents
     *
     * @return array<string, mixed>
     */
    private function publicContents(array $contents): array
    {
        $contents['files'] = array_map(static function (array $f): array {
            unset($f['uploadedBy']);

            return $f;
        }, $contents['files']);
        $contents['permission'] = 'viewer';
        $contents['capabilities'] = array_map(static fn (): bool => false, $contents['capabilities']);
        $contents['capabilities']['view'] = true;

        return $contents;
    }
}
