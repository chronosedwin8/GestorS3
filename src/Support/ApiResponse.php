<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Respuestas JSON con forma consistente:
 *   { "ok": true, "data": {...} }  |  { "ok": false, "error": { "code": "...", "message": "..." } }
 */
final class ApiResponse
{
    public static function ok(ResponseInterface $response, mixed $data = null, int $status = 200): ResponseInterface
    {
        return self::json($response, ['ok' => true, 'data' => $data ?? new \stdClass()], $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(ResponseInterface $response, string $code, string $message, int $status = 400, array $details = []): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json($response, ['ok' => false, 'error' => $error], $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
