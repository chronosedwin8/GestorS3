<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Http\RequestHelper;
use App\Services\AuthService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ProfileController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly AuthService $auth)
    {
        parent::__construct($twig, $config, $session);
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->render($response, 'profile/index.html.twig', ['profile' => $this->user($request)]);
    }

    public function update(Request $request, Response $response): Response
    {
        try {
            $this->auth->updateProfile($this->user($request), RequestHelper::str($this->body($request), 'name'));
            $this->session->flash('success', 'Tus datos se guardaron.');
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/profile');
    }

    public function password(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        $body = $this->body($request);
        try {
            if (($user['limited_session'] ?? false) === true && ($user['password_hash'] ?? null) !== null) {
                throw new AppException('Para cambiar la contraseña inicia sesión con tu contraseña actual, o usa "Olvidé mi contraseña".', 'limited_session', 403);
            }
            $this->auth->changePassword(
                $user,
                RequestHelper::str($body, 'current_password'),
                RequestHelper::str($body, 'password'),
                RequestHelper::str($body, 'password_confirmation'),
                $this->ctx($request),
            );
            $this->session->flash('success', 'Tu contraseña se actualizó.');
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/profile');
    }
}
