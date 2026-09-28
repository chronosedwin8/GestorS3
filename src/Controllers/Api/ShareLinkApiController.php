<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Services\ShareLinkService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ShareLinkApiController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly ShareLinkService $links)
    {
        parent::__construct($twig, $config, $session);
    }

    /**
     * @param array<string, string> $args
     */
    public function list(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, ['links' => $this->links->list($this->user($request), $args['uuid'])]);
    }

    /**
     * @param array<string, string> $args
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->links->create($this->user($request), $args['uuid'], $this->body($request), $this->ctx($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $this->links->revoke($this->user($request), $args['uuid'], $this->ctx($request));

        return $this->ok($response);
    }
}
