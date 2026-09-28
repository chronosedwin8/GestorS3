<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\RequestHelper;
use App\Services\FileService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class FileApiController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly FileService $files)
    {
        parent::__construct($twig, $config, $session);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->files->details($this->user($request), $args['uuid']));
    }

    /**
     * @param array<string, string> $args
     */
    public function rename(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->files->rename($this->user($request), $args['uuid'], RequestHelper::str($this->body($request), 'name'), $this->ctx($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->files->delete($this->user($request), $args['uuid'], $this->ctx($request));

        return $this->ok($response);
    }

    /**
     * Redirección 302 a la URL prefirmada: el archivo se descarga directo desde S3.
     *
     * @param array<string, string> $args
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $url = $this->files->downloadUrl($this->user($request), $args['uuid'], $this->ctx($request));

        return $response->withStatus(302)->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $args
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        return $this->ok($response, $this->files->preview($this->user($request), $args['uuid'], $this->ctx($request)));
    }
}
