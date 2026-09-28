<?php

declare(strict_types=1);

namespace App\Repositories;

final class ShareLinkRepository extends Repository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insert('share_links', $data);
    }

    /** @return array<string, mixed>|null */
    public function findByTokenHash(string $hash): ?array
    {
        return $this->one(
            'SELECT s.*, f.uuid AS folder_uuid, f.name AS folder_name, f.root_id, f.deleted_at AS folder_deleted_at,
                    u.name AS creator_name
             FROM share_links s JOIN folders f ON f.id = s.folder_id JOIN users u ON u.id = s.created_by
             WHERE s.token_hash = ?',
            [$hash],
        );
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('SELECT s.*, f.root_id FROM share_links s JOIN folders f ON f.id = s.folder_id WHERE s.uuid = ?', [$uuid]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForFolder(int $folderId): array
    {
        return $this->all(
            'SELECT s.*, u.name AS creator_name FROM share_links s JOIN users u ON u.id = s.created_by
             WHERE s.folder_id = ? ORDER BY s.revoked_at IS NULL DESC, s.created_at DESC',
            [$folderId],
        );
    }

    public function revoke(int $id): void
    {
        $this->exec('UPDATE share_links SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [self::now(), $id]);
    }

    /**
     * Incrementa el contador si no se alcanzó el máximo. Devuelve false si ya no hay descargas disponibles.
     */
    public function incrementDownloads(int $id): bool
    {
        return $this->exec(
            'UPDATE share_links SET downloads = downloads + 1 WHERE id = ? AND (max_downloads IS NULL OR downloads < max_downloads)',
            [$id],
        ) === 1;
    }
}
