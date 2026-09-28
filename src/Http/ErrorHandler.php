<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\AppException;
use App\Support\ApiResponse;
use App\Support\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Manejador de errores: JSON consistente para la API, página amable para HTML.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly Twig $twig,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails): ResponseInterface
    {
        [$status, $code, $message, $details] = $this->describe($exception);
        if ($status >= 500) {
            $this->logger->error($exception->getMessage(), [
                'exception' => $exception::class,
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'path' => $request->getUri()->getPath(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
        $base = $this->config->basePath();
        $response = $this->responses->createResponse();
        if (RequestHelper::wantsJson($request, $base)) {
            if ($displayErrorDetails && $status >= 500) {
                $details['debug'] = $exception::class . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine();
            }
            $response = ApiResponse::error($response, $code, $message, $status, $details);
            if (isset($details['retryAfter'])) {
                $response = $response->withHeader('Retry-After', (string) $details['retryAfter']);
            }

            return $response;
        }
        if ($status === 401) {
            return $response->withStatus(302)->withHeader('Location', $base . '/login');
        }
        try {
            return $this->twig->render($response->withStatus($status), 'errors/error.html.twig', [
                'status' => $status,
                'code' => $code,
                'message' => $message,
                'debug' => $displayErrorDetails && $status >= 500 ? $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() : null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical('Error renderizando la página de error', ['error' => $e->getMessage()]);
            $response->getBody()->write('<h1>Error ' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>');

            return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}
     */
    private function describe(\Throwable $e): array
    {
        return match (true) {
            $e instanceof AppException => [$e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->details()],
            $e instanceof HttpNotFoundException => [404, 'not_found', 'La página que buscas no existe.', []],
            $e instanceof HttpMethodNotAllowedException => [405, 'method_not_allowed', 'Método no permitido.', []],
            $e instanceof HttpException => [$e->getCode() ?: 400, 'http_error', $e->getMessage(), []],
            $e instanceof \JsonException => [400, 'invalid_json', 'La solicitud no tiene un formato válido.', []],
            default => [500, 'server_error', 'Ocurrió un error inesperado. Inténtalo de nuevo; si persiste, avisa al administrador.', []],
        };
    }
}
