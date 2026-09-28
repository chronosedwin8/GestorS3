<?php

declare(strict_types=1);

namespace App\Repositories;

final class UploadSessionRepository extends Repository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insert('upload_sessions', $data);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one(
            'SELECT s.*, fi.uuid AS file_uuid, fi.s3_key, fi.size_bytes, fi.name AS file_name, fi.status AS file_status,
                    fi.folder_id, fo.root_id
             FROM upload_sessions s
             JOIN files fi ON fi.id = s.file_id
             JOIN folders fo ON fo.id = fi.folder_id
             WHERE s.uuid = ?',
            [$uuid],
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->exec('UPDATE upload_sessions SET status = ?, updated_at = ? WHERE id = ?', [$status, self::now(), $id]);
    }

    /**
     * @param list<array{partNumber: int, etag: string}> $parts
     */
    public function saveCompletedParts(int $id, array $parts): void
    {
        $this->exec('UPDATE upload_sessions SET completed_parts = ?, updated_at = ? WHERE id = ?', [json_encode($parts), self::now(), $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function staleActive(string $createdBefore, int $limit = 500): array
    {
        return $this->all(
            "SELECT s.id, s.s3_upload_id, s.file_id, fi.s3_key
             FROM upload_sessions s JOIN files fi ON fi.id = s.file_id
             WHERE s.status = 'active' AND (s.created_at < ? OR s.expires_at < ?)
             LIMIT $limit",
            [$createdBefore, self::now()],
        );
    }

    /** @return array<string, mixed>|null */
    public function activeForFile(int $fileId): ?array
    {
        return $this->one("SELECT * FROM upload_sessions WHERE file_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$fileId]);
    }
}
