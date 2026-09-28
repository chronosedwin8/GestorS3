<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Datos del cliente de la petición en curso (IP y agente de usuario) para auditoría y rate limiting.
 */
final class RequestContext
{
    public function __construct(
        public readonly string $ip = '0.0.0.0',
        public readonly string $userAgent = '',
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $server = $request->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        // Detrás de un proxy de confianza (Nginx local) se puede usar X-Forwarded-For.
        if (in_array($ip, ['127.0.0.1', '::1'], true) && $request->hasHeader('X-Forwarded-For')) {
            $forwarded = trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                $ip = $forwarded;
            }
        }

        return new self(substr($ip, 0, 45), mb_substr($request->getHeaderLine('User-Agent'), 0, 255));
    }
}
