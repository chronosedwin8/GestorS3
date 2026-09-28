<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Services\UploadService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Protocolo de subida directa a S3 (§6.2).
 */
final class UploadApiController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly UploadService $uploads)
    {
        parent::__construct($twig, $config, $session);
    }

    /**
     * @param array<string, string> $args
     */
    public function init(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->uploads->init($this->user($request), $args['uuid'], $this->body($request), $this->ctx($request)), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function signParts(Request $request, Response $response, array $args): Response
    {
        $numbers = $this->body($request)['partNumbers'] ?? [];

        return $this->ok($response, $this->uploads->signParts($this->user($request), $args['uuid'], is_array($numbers) ? array_values($numbers) : []));
    }

    /**
     * @param array<string, string> $args
     */
    public function complete(Request $request, Response $response, array $args): Response
    {
        $parts = $this->body($request)['parts'] ?? [];

        return $this->ok($response, $this->uploads->complete($this->user($request), $args['uuid'], is_array($parts) ? array_values($parts) : [], $this->ctx($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->uploads->status($this->user($request), $args['uuid']));
    }

    /**
     * @param array<string, string> $args
     */
    public function abort(Request $request, Response $response, array $args): Response
    {
        $this->uploads->abort($this->user($request), $args['uuid'], $this->ctx($request));

        return $this->ok($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->uploads->confirm($this->user($request), $args['uuid'], $this->ctx($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function abortSingle(Request $request, Response $response, array $args): Response
    {
        $this->uploads->abortSingle($this->user($request), $args['uuid']);

        return $this->ok($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function signSingle(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->uploads->signSingle($this->user($request), $args['uuid']));
    }

    /**
     * @param array<string, string> $args
     */
    public function notify(Request $request, Response $response, array $args): Response
    {
        $count = (int) ($this->body($request)['count'] ?? 0);

        return $this->ok($response, ['notified' => $this->uploads->notifyUploaded($this->user($request), $args['uuid'], $count)]);
    }
}
