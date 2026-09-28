<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Tokens de un solo uso asociados a un usuario: tablas magic_links y password_resets.
 */
final class TokenRepository extends Repository
{
    private const TABLES = ['magic_links', 'password_resets'];

    public function create(string $table, int $userId, string $tokenHash, string $expiresAt): void
    {
        $this->assertTable($table);
        $this->insert($table, [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => self::now(),
        ]);
    }

    /**
     * Token válido (no usado y no expirado).
     *
     * @return array<string, mixed>|null
     */
    public function findValid(string $table, string $tokenHash): ?array
    {
        $this->assertTable($table);

        return $this->one(
            "SELECT * FROM $table WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?",
            [$tokenHash, self::now()],
        );
    }

    /**
     * Marca como usado de forma atómica. Devuelve false si otro proceso lo usó antes.
     */
    public function markUsed(string $table, int $id): bool
    {
        $this->assertTable($table);

        return $this->exec("UPDATE $table SET used_at = ? WHERE id = ? AND used_at IS NULL", [self::now(), $id]) === 1;
    }

    public function invalidateForUser(string $table, int $userId): void
    {
        $this->assertTable($table);
        $this->exec("UPDATE $table SET used_at = ? WHERE user_id = ? AND used_at IS NULL", [self::now(), $userId]);
    }

    public function purgeExpired(): int
    {
        $count = 0;
        foreach (self::TABLES as $table) {
            $count += $this->exec("DELETE FROM $table WHERE expires_at < ?", [self::now('-7 days')]);
        }

        return $count;
    }

    private function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Tabla de tokens no válida.');
        }
    }
}
