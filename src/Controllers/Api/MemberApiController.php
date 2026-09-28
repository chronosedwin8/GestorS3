<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\RequestHelper;
use App\Services\MemberService;
use App\Support\Config;
use App\Support\Session;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class MemberApiController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly MemberService $members)
    {
        parent::__construct($twig, $config, $session);
    }

    /**
     * @param array<string, string> $args
     */
    public function list(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->members->list($this->user($request), $args['uuid']));
    }

    /**
     * @param array<string, string> $args
     */
    public function invite(Request $request, Response $response, array $args): Response
    {
        $body = $this->body($request);
        $emails = is_array($body['emails'] ?? null)
            ? RequestHelper::stringList($body, 'emails')
            : Validator::splitEmails(RequestHelper::str($body, 'emails'));
        $results = $this->members->invite($this->user($request), $args['uuid'], $emails, RequestHelper::str($body, 'permission', 'viewer'), $this->ctx($request));

        return $this->ok($response, ['results' => $results]);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $this->members->changePermission($this->user($request), $args['uuid'], $args['member'], RequestHelper::str($this->body($request), 'permission'), $this->ctx($request));

        return $this->ok($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function remove(Request $request, Response $response, array $args): Response
    {
        $this->members->remove($this->user($request), $args['uuid'], $args['member'], $this->ctx($request));

        return $this->ok($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function cancelInvitation(Request $request, Response $response, array $args): Response
    {
        $this->members->cancelInvitation($this->user($request), $args['uuid'], $this->ctx($request));

        return $this->ok($response);
    }
}
