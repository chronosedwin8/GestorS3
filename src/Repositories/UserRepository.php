<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    private const SELECT = 'SELECT u.*, e.name AS entity_name, e.uuid AS entity_uuid
        FROM users u LEFT JOIN entities e ON e.id = u.entity_id';

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.uuid = ?', [$uuid]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one(self::SELECT . ' WHERE u.email = ?', [mb_strtolower(trim($email))]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insert('users', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateById('users', $id, $data);
    }

    public function touchLogin(int $id): void
    {
        $this->exec('UPDATE users SET last_login_at = ? WHERE id = ?', [self::now(), $id]);
    }

    public function countAdmins(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $query, ?int $entityId, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($query !== '') {
            $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . self::escapeLike($query) . '%';
        }
        if ($entityId !== null) {
            $where[] = 'u.entity_id = :entity';
            $params['entity'] = $entityId;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->scalar("SELECT COUNT(*) FROM users u WHERE $whereSql", $params);
        $items = $this->all(self::SELECT . " WHERE $whereSql ORDER BY u.name ASC LIMIT $limit OFFSET $offset", $params);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Sugerencias para autocompletar al invitar (solo usuarios activos).
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(string $query, int $limit = 8): array
    {
        $like = '%' . self::escapeLike($query) . '%';

        return $this->all(
            self::SELECT . " WHERE u.status = 'active' AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY u.name LIMIT $limit",
            [$like, $like],
        );
    }
}
