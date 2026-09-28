<?php

declare(strict_types=1);

namespace App\Repositories;

final class FolderMemberRepository extends Repository
{
    public function permission(int $rootId, int $userId): ?string
    {
        $value = $this->scalar('SELECT permission FROM folder_members WHERE folder_id = ? AND user_id = ?', [$rootId, $userId]);

        return is_string($value) ? $value : null;
    }

    public function add(int $rootId, int $userId, string $permission, ?int $addedBy): void
    {
        $this->exec(
            'INSERT INTO folder_members (folder_id, user_id, permission, added_by, created_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE permission = VALUES(permission)',
            [$rootId, $userId, $permission, $addedBy, self::now()],
        );
    }

    public function updatePermission(int $rootId, int $userId, string $permission): void
    {
        $this->exec('UPDATE folder_members SET permission = ? WHERE folder_id = ? AND user_id = ?', [$permission, $rootId, $userId]);
    }

    public function remove(int $rootId, int $userId): void
    {
        $this->exec('DELETE FROM folder_members WHERE folder_id = ? AND user_id = ?', [$rootId, $userId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForFolder(int $rootId): array
    {
        return $this->all(
            "SELECT m.permission, m.created_at, u.id AS user_id, u.uuid AS user_uuid, u.name, u.email, u.status,
                    e.name AS entity_name
             FROM folder_members m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN entities e ON e.id = u.entity_id
             WHERE m.folder_id = ?
             ORDER BY FIELD(m.permission, 'owner', 'editor', 'viewer'), u.name",
            [$rootId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recipients(int $rootId, ?int $exceptUserId = null): array
    {
        return $this->all(
            "SELECT u.id, u.name, u.email FROM folder_members m JOIN users u ON u.id = m.user_id
             WHERE m.folder_id = ? AND u.status = 'active' AND u.id <> ?",
            [$rootId, $exceptUserId ?? 0],
        );
    }
}
