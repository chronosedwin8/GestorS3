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

final class AuthController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly AuthService $auth)
    {
        parent::__construct($twig, $config, $session);
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($request->getAttribute('user') !== null) {
            return $this->redirect($response, RequestHelper::safeNext($this->query($request, 'next')));
        }

        return $this->render($response, 'auth/login.html.twig', [
            'next' => RequestHelper::safeNext($this->query($request, 'next')),
            'tab' => $this->query($request, 'tab', 'password'),
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $next = RequestHelper::safeNext(RequestHelper::str($body, 'next'));
        try {
            $this->auth->attempt(RequestHelper::str($body, 'email'), RequestHelper::str($body, 'password'), $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->session->flashOld($body);

            return $this->redirect($response, '/login' . ($next !== '/' ? '?next=' . rawurlencode($next) : ''));
        }

        return $this->redirect($response, $next);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout($this->ctx($request));
        $this->session->start();
        $this->session->flash('success', 'Cerraste sesión correctamente.');

        return $this->redirect($response, '/login');
    }

    public function showForgot(Request $request, Response $response): Response
    {
        return $this->render($response, 'auth/forgot.html.twig');
    }

    public function forgot(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        try {
            $this->auth->requestPasswordReset(RequestHelper::str($body, 'email'), $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect($response, '/forgot-password');
        }

        return $this->render($response, 'auth/check-email.html.twig', [
            'email' => RequestHelper::str($body, 'email'),
            'purpose' => 'reset',
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function showReset(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->findResetUser($args['token']);

        return $this->render($response, 'auth/reset.html.twig', [
            'token' => $args['token'],
            'valid' => $user !== null,
            'email' => $user['email'] ?? null,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);
        try {
            $this->auth->resetPassword($args['token'], RequestHelper::str($body, 'password'), RequestHelper::str($body, 'password_confirmation'), $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect($response, '/reset-password/' . rawurlencode($args['token']));
        }
        $this->session->flash('success', 'Tu contraseña se actualizó. ¡Bienvenido de nuevo!');

        return $this->redirect($response, '/');
    }

    public function requestMagic(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        try {
            $this->auth->requestMagicLink(RequestHelper::str($body, 'email'), $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect($response, '/login?tab=magic');
        }

        return $this->render($response, 'auth/check-email.html.twig', [
            'email' => RequestHelper::str($body, 'email'),
            'purpose' => 'magic',
        ]);
    }

    /**
     * Página intermedia: evita que los escáneres de correo consuman el enlace al pre-visitarlo.
     *
     * @param array<string, string> $args
     */
    public function showMagic(Request $request, Response $response, array $args): Response
    {
        return $this->render($response, 'auth/magic.html.twig', [
            'token' => $args['token'],
            'valid' => $this->auth->magicLinkIsValid($args['token']),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function consumeMagic(Request $request, Response $response, array $args): Response
    {
        try {
            $this->auth->consumeMagicLink($args['token'], $this->ctx($request));
        } catch (AppException $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->redirect($response, '/login?tab=magic');
        }

        return $this->redirect($response, '/');
    }
}
