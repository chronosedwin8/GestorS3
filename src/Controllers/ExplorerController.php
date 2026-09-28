<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FolderService;
use App\Support\Config;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Carcasa de la aplicación (inicio y vista de carpeta). La navegación entre carpetas ocurre en el cliente
 * para que la cola de subidas persista sin recargar la página.
 */
final class ExplorerController extends Controller
{
    public function __construct(Twig $twig, Config $config, Session $session, private readonly FolderService $folders)
    {
        parent::__construct($twig, $config, $session);
    }

    public function home(Request $request, Response $response): Response
    {
        return $this->render($response, 'folders/explorer.html.twig', [
            'initial' => ['view' => 'home', 'home' => $this->folders->home($this->user($request))],
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function folder(Request $request, Response $response, array $args): Response
    {
        $contents = $this->folders->contents($this->user($request), $args['uuid']);

        return $this->render($response, 'folders/explorer.html.twig', [
            'initial' => ['view' => 'folder', 'folder' => $contents],
            'pageTitle' => $contents['folder']['name'],
        ]);
    }
}
