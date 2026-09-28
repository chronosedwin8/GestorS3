<?php

declare(strict_types=1);

namespace App\Repositories;

final class EntityRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM entities WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('SELECT * FROM entities WHERE uuid = ?', [$uuid]);
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->one('SELECT * FROM entities WHERE name = ?', [trim($name)]);
    }

    /** @return array<string, mixed>|null */
    public function findByDomain(string $domain): ?array
    {
        return $this->one('SELECT * FROM entities WHERE domain = ? ORDER BY id LIMIT 1', [mb_strtolower($domain)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->all(
            'SELECT e.*, (SELECT COUNT(*) FROM users u WHERE u.entity_id = e.id) AS users_count
             FROM entities e ORDER BY e.name',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->all('SELECT id, uuid, name, domain FROM entities ORDER BY name');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insert('entities', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateById('entities', $id, $data);
    }

    public function delete(int $id): void
    {
        $this->exec('DELETE FROM entities WHERE id = ?', [$id]);
    }
}
