<?php

declare(strict_types=1);

namespace App\Repositories;

final class FileRepository extends Repository
{
    private const SELECT = 'SELECT fi.*, u.name AS uploader_name, u.email AS uploader_email,
            fo.uuid AS folder_uuid, fo.root_id AS root_id, fo.name AS folder_name, fo.path_cache AS folder_path
        FROM files fi
        JOIN users u ON u.id = fi.uploaded_by
        JOIN folders fo ON fo.id = fi.folder_id';

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE fi.id = ?', [$id]);
    }

    /**
     * Archivo vivo por uuid (cualquier estado).
     *
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one(self::SELECT . ' WHERE fi.uuid = ? AND fi.deleted_at IS NULL AND fo.deleted_at IS NULL', [$uuid]);
    }

    /**
     * Archivo por uuid incluyendo versiones anteriores (reemplazadas).
     *
     * @return array<string, mixed>|null
     */
    public function findVersionByUuid(string $uuid): ?array
    {
        return $this->one(
            self::SELECT . ' WHERE fi.uuid = ? AND fo.deleted_at IS NULL AND (fi.deleted_at IS NULL OR fi.superseded_at IS NOT NULL)',
            [$uuid],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insert('files', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateById('files', $id, $data);
    }

    /**
     * Archivo vivo con el mismo nombre en la carpeta (listo o en subida).
     *
     * @return array<string, mixed>|null
     */
    public function findLiveByName(int $folderId, string $name, bool $includeUploading = true): ?array
    {
        $statuses = $includeUploading ? "('ready', 'uploading')" : "('ready')";

        return $this->one(
            "SELECT * FROM files WHERE folder_id = ? AND name = ? AND deleted_at IS NULL AND status IN $statuses
             ORDER BY FIELD(status, 'ready', 'uploading') LIMIT 1",
            [$folderId, $name],
        );
    }

    public function nameTaken(int $folderId, string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM files WHERE folder_id = ? AND name = ? AND deleted_at IS NULL AND status IN ('ready', 'uploading') AND id <> ? LIMIT 1",
            [$folderId, $name, $exceptId ?? 0],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReady(int $folderId): array
    {
        return $this->all(
            "SELECT fi.id, fi.uuid, fi.name, fi.extension, fi.mime_type, fi.size_bytes, fi.version, fi.created_at, fi.updated_at,
                    u.name AS uploader_name
             FROM files fi JOIN users u ON u.id = fi.uploaded_by
             WHERE fi.folder_id = ? AND fi.status = 'ready' AND fi.deleted_at IS NULL
             ORDER BY fi.name",
            [$folderId],
        );
    }

    /**
     * Archivos listos por uuid dentro de un conjunto de carpetas.
     *
     * @param list<string> $uuids
     * @param list<int> $folderIds
     *
     * @return list<array<string, mixed>>
     */
    public function findReadyByUuids(array $uuids, array $folderIds): array
    {
        if ($uuids === [] || $folderIds === []) {
            return [];
        }
        $params = [];
        $inU = $this->inClause($uuids, $params, 'u');
        $inF = $this->inClause($folderIds, $params, 'f');

        return $this->all(
            "SELECT * FROM files WHERE uuid IN ($inU) AND folder_id IN ($inF) AND status = 'ready' AND deleted_at IS NULL",
            $params,
        );
    }

    /**
     * Archivos listos de un conjunto de carpetas (para ZIP).
     *
     * @param list<int> $folderIds
     *
     * @return list<array<string, mixed>>
     */
    public function readyInFolders(array $folderIds): array
    {
        if ($folderIds === []) {
            return [];
        }
        $params = [];
        $in = $this->inClause($folderIds, $params);

        return $this->all(
            "SELECT id, uuid, folder_id, name, size_bytes, s3_key, mime_type, created_at FROM files
             WHERE folder_id IN ($in) AND status = 'ready' AND deleted_at IS NULL ORDER BY folder_id, name",
            $params,
        );
    }

    /**
     * Cadena de versiones anteriores (más reciente primero).
     *
     * @return list<array<string, mixed>>
     */
    public function versions(int $fileId): array
    {
        return $this->all(
            'WITH RECURSIVE chain AS (
                SELECT id, previous_version_id, 0 AS depth FROM files WHERE id = ?
                UNION ALL
                SELECT p.id, p.previous_version_id, c.depth + 1 FROM files p JOIN chain c ON p.id = c.previous_version_id
                WHERE c.depth < 100
            )
            SELECT fi.id, fi.uuid, fi.s3_key, fi.name, fi.size_bytes, fi.version, fi.created_at, fi.superseded_at, u.name AS uploader_name, c.depth
            FROM chain c JOIN files fi ON fi.id = c.id JOIN users u ON u.id = fi.uploaded_by
            ORDER BY c.depth ASC',
            [$fileId],
        );
    }

    /**
     * @param list<int> $ids
     */
    public function softDelete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $params = ['now' => self::now()];
        $in = $this->inClause($ids, $params);

        return $this->exec("UPDATE files SET deleted_at = :now WHERE id IN ($in) AND deleted_at IS NULL", $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(?int $userId, string $query, int $limit = 20): array
    {
        $params = ['q' => '%' . self::escapeLike($query) . '%'];
        $access = '';
        if ($userId !== null) {
            $access = 'AND fo.root_id IN (SELECT folder_id FROM folder_members WHERE user_id = :uid)';
            $params['uid'] = $userId;
        }

        return $this->all(
            "SELECT fi.uuid, fi.name, fi.extension, fi.size_bytes, fi.created_at, fo.uuid AS folder_uuid, fo.path_cache AS folder_path
             FROM files fi JOIN folders fo ON fo.id = fi.folder_id
             WHERE fi.status = 'ready' AND fi.deleted_at IS NULL AND fo.deleted_at IS NULL AND fi.name LIKE :q $access
             ORDER BY fi.created_at DESC LIMIT $limit",
            $params,
        );
    }

    /**
     * Archivos en subida abandonados (subida simple sin confirmar) o fallidos.
     *
     * @return list<array<string, mixed>>
     */
    public function staleUploads(string $uploadingBefore, string $failedBefore, int $limit = 500): array
    {
        return $this->all(
            "SELECT fi.id, fi.uuid, fi.s3_key, fi.status,
                    (SELECT COUNT(*) FROM upload_sessions s WHERE s.file_id = fi.id AND s.status = 'active') AS active_sessions
             FROM files fi
             WHERE (fi.status = 'uploading' AND fi.created_at < ?) OR (fi.status = 'failed' AND fi.updated_at < ?)
             LIMIT $limit",
            [$uploadingBefore, $failedBefore],
        );
    }

    /**
     * Archivos borrados (no reemplazados) antes de una fecha, con su cadena de versiones, para purga definitiva.
     *
     * @return list<array<string, mixed>>
     */
    public function purgeCandidates(string $deletedBefore, int $limit = 500): array
    {
        return $this->all(
            "SELECT id, s3_key FROM files
             WHERE deleted_at IS NOT NULL AND deleted_at < ? AND superseded_at IS NULL
             LIMIT $limit",
            [$deletedBefore],
        );
    }

    public function hardDelete(int $id): void
    {
        $this->exec('DELETE FROM files WHERE id = ?', [$id]);
    }
}
