<?php

declare(strict_types=1);

namespace App\Repositories;

final class RateLimitRepository extends Repository
{
    /**
     * Incrementa el contador de la ventana y devuelve [hits, resetAt].
     *
     * @return array{hits: int, reset_at: string}
     */
    public function hit(string $key, int $windowSeconds): array
    {
        $now = self::now();
        $reset = self::now('+' . $windowSeconds . ' seconds');
        $this->exec(
            'INSERT INTO rate_limits (rkey, hits, reset_at) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                hits = IF(reset_at <= ?, 1, hits + 1),
                reset_at = IF(reset_at <= ?, VALUES(reset_at), reset_at)',
            [$key, $reset, $now, $now],
        );
        $row = $this->one('SELECT hits, reset_at FROM rate_limits WHERE rkey = ?', [$key]);

        return ['hits' => (int) ($row['hits'] ?? 1), 'reset_at' => (string) ($row['reset_at'] ?? $reset)];
    }

    public function clear(string $key): void
    {
        $this->exec('DELETE FROM rate_limits WHERE rkey = ?', [$key]);
    }

    public function purgeExpired(): int
    {
        return $this->exec('DELETE FROM rate_limits WHERE reset_at < ?', [self::now()]);
    }
}
