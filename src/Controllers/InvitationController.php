<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Repositories\EntityRepository;
use App\Services\AuthService;
use App\Services\InvitationService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class InvitationController extends Controller
{
    public function __construct(
        Twig $twig,
        Config $config,
        Session $session,
        private readonly InvitationService $invitations,
        private readonly EntityRepository $entities,
        private readonly AuthService $auth,
    ) {
        parent::__construct($twig, $config, $session);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $invitation = $this->invitations->findValid($args['token']);
        } catch (AppException $e) {
            return $this->render($response, 'auth/invitation-invalid.html.twig', ['message' => $e->getMessage()], 404);
        }
        // Si ya tiene cuenta: aplicar el acceso y enviar al login.
        $existing = $this->invitations->applyToExistingUser($invitation);
        if ($existing !== null) {
            $current = $request->getAttribute('user');
            $target = $invitation['folder_uuid'] !== null ? '/folders/' . $invitation['folder_uuid'] : '/';
            if (is_array($current) && (int) $current['id'] === (int) $existing['id']) {
                $this->session->flash('success', 'Listo: ya tienes acceso.');

                return $this->redirect($response, $target);
            }
            if (is_array($current)) {
                $this->auth->logout($this->ctx($request));
                $this->session->start();
            }
            $this->session->flash('success', 'Ya tienes una cuenta con este correo: inicia sesión para ver lo que te compartieron.');
            $this->session->flashOld(['email' => $existing['email']]);

            return $this->redirect($response, '/login?next=' . rawurlencode($target));
        }

        return $this->render($response, 'auth/invitation.html.twig', [
            'invitation' => $invitation,
            'token' => $args['token'],
            'entities' => $invitation['entity_id'] === null ? $this->entities->list() : [],
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function accept(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);
        try {
            $invitation = $this->invitations->findValid($args['token']);
            if ($request->getAttribute('user') !== null) {
                $this->auth->logout($this->ctx($request));
                $this->session->start();
            }
            $result = $this->invitations->accept($invitation, $body, $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->session->flashOld($body);

            return $this->redirect($response, '/invitation/' . rawurlencode($args['token']));
        }
        $this->session->flash('success', sprintf('¡Bienvenido, %s! Tu cuenta está lista.', $result['user']['name']));

        return $this->redirect($response, $result['folderUuid'] !== null ? '/folders/' . $result['folderUuid'] : '/');
    }
}
