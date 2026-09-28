<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Base de los repositorios: helpers finos sobre PDO. Siempre sentencias preparadas.
 * Todas las fechas se guardan en UTC.
 */
abstract class Repository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    /**
     * @param array<string|int, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    protected function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    protected function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->exec($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function updateById(string $table, int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => sprintf('`%s` = :%s', $c, $c), array_keys($data)));
        $data['__id'] = $id;

        return $this->exec(sprintf('UPDATE %s SET %s WHERE id = :__id', $table, $sets), $data);
    }

    /**
     * Genera placeholders nombrados para cláusulas IN.
     *
     * @param list<int|string> $values
     * @param array<string, mixed> $params
     */
    protected function inClause(array $values, array &$params, string $prefix = 'in'): string
    {
        if ($values === []) {
            return 'NULL';
        }
        $names = [];
        foreach ($values as $i => $value) {
            $name = $prefix . $i;
            $params[$name] = $value;
            $names[] = ':' . $name;
        }

        return implode(', ', $names);
    }

    public static function now(string $modify = ''): string
    {
        $date = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($modify !== '') {
            $date = $date->modify($modify);
        }

        return $date->format('Y-m-d H:i:s');
    }

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
