<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TooManyRequestsException;
use App\Repositories\RateLimitRepository;

/**
 * Rate limiting de ventana fija respaldado en MySQL (funciona con varios servidores PHP-FPM).
 */
final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repository)
    {
    }

    /**
     * Registra un intento y lanza excepción si se supera el máximo en la ventana.
     */
    public function hit(string $key, int $max, int $windowSeconds, ?string $message = null): void
    {
        $key = mb_substr($key, 0, 191);
        $result = $this->repository->hit($key, $windowSeconds);
        if ($result['hits'] > $max) {
            $retry = max(1, strtotime($result['reset_at'] . ' UTC') - time());
            $minutes = (int) ceil($retry / 60);
            throw new TooManyRequestsException(
                $message ?? sprintf('Demasiados intentos. Espera %s e inténtalo de nuevo.', $minutes <= 1 ? 'un minuto' : $minutes . ' minutos'),
                'too_many_requests',
                ['retryAfter' => $retry],
            );
        }
    }

    public function clear(string $key): void
    {
        $this->repository->clear(mb_substr($key, 0, 191));
    }
}
