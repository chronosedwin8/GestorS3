<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Http\RequestHelper;
use App\Services\AdminService;
use App\Services\SettingsService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Panel de administración (solo rol admin).
 */
final class AdminController extends Controller
{
    public function __construct(
        Twig $twig,
        Config $config,
        Session $session,
        private readonly AdminService $admin,
        private readonly SettingsService $settings,
    ) {
        parent::__construct($twig, $config, $session);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->redirect($response, '/admin/users');
    }

    public function users(Request $request, Response $response): Response
    {
        $page = max(1, (int) $this->query($request, 'page', '1'));
        $q = $this->query($request, 'q');
        $entity = $this->query($request, 'entity');
        $result = $this->admin->users($q, $entity !== '' ? $entity : null, $page);
        $inviteResults = $this->session->get('_invite_results');
        $this->session->remove('_invite_results');

        return $this->render($response, 'admin/users.html.twig', [
            'section' => 'users',
            'users' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / 25)),
            'q' => $q,
            'entity' => $entity,
            'entities' => $this->admin->entityOptions(),
            'invitations' => $this->admin->pendingInvitations(),
            'inviteResults' => is_array($inviteResults) ? $inviteResults : null,
        ]);
    }

    public function inviteUsers(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        try {
            $results = $this->admin->inviteUsers($this->user($request), RequestHelper::str($body, 'emails'), RequestHelper::str($body, 'entity'), $this->ctx($request));
            $this->session->set('_invite_results', $results);
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/users');
    }

    /**
     * @param array<string, string> $args
     */
    public function updateUser(Request $request, Response $response, array $args): Response
    {
        try {
            $this->admin->updateUser($this->user($request), $args['uuid'], $this->body($request), $this->ctx($request));
            $this->session->flash('success', 'Usuario actualizado.');
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/users');
    }

    public function entities(Request $request, Response $response): Response
    {
        return $this->render($response, 'admin/entities.html.twig', [
            'section' => 'entities',
            'entities' => $this->admin->entities(),
        ]);
    }

    public function createEntity(Request $request, Response $response): Response
    {
        return $this->saveEntity($request, $response, null);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateEntity(Request $request, Response $response, array $args): Response
    {
        return $this->saveEntity($request, $response, $args['uuid']);
    }

    private function saveEntity(Request $request, Response $response, ?string $uuid): Response
    {
        try {
            $this->admin->saveEntity($this->user($request), $uuid, $this->body($request), $this->ctx($request));
            $this->session->flash('success', $uuid === null ? 'Entidad creada.' : 'Entidad actualizada.');
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/entities');
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteEntity(Request $request, Response $response, array $args): Response
    {
        try {
            $this->admin->deleteEntity($this->user($request), $args['uuid'], $this->ctx($request));
            $this->session->flash('success', 'Entidad eliminada. Sus usuarios quedaron sin entidad.');
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/entities');
    }

    public function settings(Request $request, Response $response): Response
    {
        return $this->render($response, 'admin/settings.html.twig', [
            'section' => 'settings',
            'settings' => $this->settings->describe(),
            'singleMax' => $this->settings->singleMaxBytes(),
            'partSize' => $this->settings->partSizeBytes(),
            's3' => [
                'bucket' => $this->config->string('s3.bucket'),
                'region' => $this->config->string('s3.region'),
                'prefix' => $this->config->string('s3.prefix'),
                'endpoint' => $this->config->string('s3.endpoint'),
            ],
        ]);
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        try {
            $this->admin->saveSettings($this->user($request), $this->body($request), $this->ctx($request));
            $this->session->flash('success', 'Límites guardados.');
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/settings');
    }

    public function activity(Request $request, Response $response): Response
    {
        $page = max(1, (int) $this->query($request, 'page', '1'));
        $filters = [
            'action' => $this->query($request, 'action'),
            'user' => $this->query($request, 'user'),
            'from' => $this->dateFilter($this->query($request, 'from')),
            'to' => $this->dateFilter($this->query($request, 'to'), true),
        ];
        $result = $this->admin->activity($filters, $page);

        return $this->render($response, 'admin/activity.html.twig', [
            'section' => 'activity',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / 50)),
            'actions' => $this->admin->activityActions(),
            'filters' => [
                'action' => $filters['action'],
                'user' => $filters['user'],
                'from' => $this->query($request, 'from'),
                'to' => $this->query($request, 'to'),
            ],
        ]);
    }

    public function storage(Request $request, Response $response): Response
    {
        $rows = $this->admin->storage();

        return $this->render($response, 'admin/storage.html.twig', [
            'section' => 'storage',
            'rows' => $rows,
            'totalBytes' => array_sum(array_map(static fn (array $r): int => (int) $r['size_bytes'], $rows)),
            'totalFiles' => array_sum(array_map(static fn (array $r): int => (int) $r['files_count'], $rows)),
            'folderMax' => $this->settings->folderMaxBytes(),
        ]);
    }

    /**
     * Convierte una fecha local (YYYY-MM-DD) a UTC para filtrar.
     */
    private function dateFilter(string $value, bool $endOfDay = false): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        try {
            $date = new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone($this->config->string('app.timezone', 'UTC')));
            if ($endOfDay) {
                $date = $date->modify('+1 day');
            }

            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return '';
        }
    }
}
