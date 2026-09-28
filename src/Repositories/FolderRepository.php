<?php

declare(strict_types=1);

namespace App\Repositories;

final class FolderRepository extends Repository
{
    private const SELECT = 'SELECT f.*, o.name AS owner_name, o.email AS owner_email, oe.name AS owner_entity
        FROM folders f
        JOIN users o ON o.id = f.owner_id
        LEFT JOIN entities oe ON oe.id = o.entity_id';

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE f.id = ? AND f.deleted_at IS NULL', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one(self::SELECT . ' WHERE f.uuid = ? AND f.deleted_at IS NULL', [$uuid]);
    }

    public function createRoot(string $uuid, int $ownerId, string $name, ?string $description): int
    {
        $now = self::now();
        $id = $this->insert('folders', [
            'uuid' => $uuid,
            'parent_id' => null,
            'root_id' => null,
            'owner_id' => $ownerId,
            'name' => $name,
            'description' => $description,
            'path_cache' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->exec('UPDATE folders SET root_id = id WHERE id = ?', [$id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $parent
     */
    public function createChild(string $uuid, array $parent, int $ownerId, string $name): int
    {
        $now = self::now();

        return $this->insert('folders', [
            'uuid' => $uuid,
            'parent_id' => (int) $parent['id'],
            'root_id' => (int) $parent['root_id'],
            'owner_id' => $ownerId,
            'name' => $name,
            'description' => null,
            'path_cache' => mb_substr($parent['path_cache'] . '/' . $name, 0, 1024),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findChildByName(int $parentId, string $name): ?array
    {
        return $this->one(self::SELECT . ' WHERE f.parent_id = ? AND f.name = ? AND f.deleted_at IS NULL', [$parentId, $name]);
    }

    /**
     * Ancestros desde la raíz hasta la carpeta (incluida).
     *
     * @return list<array<string, mixed>>
     */
    public function breadcrumb(int $folderId): array
    {
        return $this->all(
            'WITH RECURSIVE chain AS (
                SELECT id, uuid, name, parent_id, 0 AS depth FROM folders WHERE id = ?
                UNION ALL
                SELECT p.id, p.uuid, p.name, p.parent_id, c.depth + 1 FROM folders p JOIN chain c ON p.id = c.parent_id
            )
            SELECT id, uuid, name FROM chain ORDER BY depth DESC',
            [$folderId],
        );
    }

    /**
     * Subcarpetas directas con número de archivos y tamaño total (recursivo).
     *
     * @return list<array<string, mixed>>
     */
    public function children(int $parentId): array
    {
        $folders = $this->all(
            'SELECT f.id, f.uuid, f.name, f.created_at, f.updated_at, f.owner_id, o.name AS owner_name
             FROM folders f JOIN users o ON o.id = f.owner_id
             WHERE f.parent_id = ? AND f.deleted_at IS NULL ORDER BY f.name',
            [$parentId],
        );
        if ($folders === []) {
            return [];
        }
        $stats = $this->all(
            "WITH RECURSIVE tree AS (
                SELECT id, id AS top FROM folders WHERE parent_id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT c.id, t.top FROM folders c JOIN tree t ON c.parent_id = t.id WHERE c.deleted_at IS NULL
            )
            SELECT t.top, COUNT(fi.id) AS files_count, COALESCE(SUM(fi.size_bytes), 0) AS size_bytes,
                   MAX(fi.created_at) AS last_file_at
            FROM tree t LEFT JOIN files fi ON fi.folder_id = t.id AND fi.status = 'ready' AND fi.deleted_at IS NULL
            GROUP BY t.top",
            [$parentId],
        );
        $byTop = [];
        foreach ($stats as $row) {
            $byTop[(int) $row['top']] = $row;
        }
        foreach ($folders as &$folder) {
            $s = $byTop[(int) $folder['id']] ?? null;
            $folder['files_count'] = (int) ($s['files_count'] ?? 0);
            $folder['size_bytes'] = (int) ($s['size_bytes'] ?? 0);
            $folder['last_file_at'] = $s['last_file_at'] ?? null;
        }

        return $folders;
    }

    /**
     * IDs de la carpeta y todos sus descendientes vivos.
     *
     * @return list<int>
     */
    public function descendantIds(int $folderId): array
    {
        $rows = $this->all(
            'WITH RECURSIVE tree AS (
                SELECT id FROM folders WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT c.id FROM folders c JOIN tree t ON c.parent_id = t.id WHERE c.deleted_at IS NULL
            ) SELECT id FROM tree',
            [$folderId],
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Subárbol vivo (id, parent_id, name) para construir rutas relativas.
     *
     * @return list<array<string, mixed>>
     */
    public function subtree(int $folderId): array
    {
        return $this->all(
            'WITH RECURSIVE tree AS (
                SELECT id, parent_id, name, 0 AS depth FROM folders WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT c.id, c.parent_id, c.name, t.depth + 1 FROM folders c JOIN tree t ON c.parent_id = t.id WHERE c.deleted_at IS NULL
            ) SELECT id, parent_id, name, depth FROM tree ORDER BY depth',
            [$folderId],
        );
    }

    /**
     * ¿Está $folderId dentro del subárbol de $ancestorId (o es la misma)?
     */
    public function isWithin(int $folderId, int $ancestorId): bool
    {
        if ($folderId === $ancestorId) {
            return true;
        }
        foreach ($this->breadcrumb($folderId) as $crumb) {
            if ((int) $crumb['id'] === $ancestorId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Borrado lógico de la carpeta, sus subcarpetas y sus archivos.
     */
    public function softDeleteTree(int $folderId): int
    {
        $ids = $this->descendantIds($folderId);
        if ($ids === []) {
            return 0;
        }
        $params = ['now' => self::now()];
        $in = $this->inClause($ids, $params);
        $this->exec("UPDATE files SET deleted_at = :now WHERE folder_id IN ($in) AND deleted_at IS NULL", $params);
        $this->exec("UPDATE folders SET deleted_at = :now WHERE id IN ($in) AND deleted_at IS NULL", $params);

        return count($ids);
    }

    /**
     * Renombra y actualiza path_cache de todos los descendientes.
     *
     * @param array<string, mixed> $folder
     */
    public function rename(array $folder, string $newName): void
    {
        $oldPath = (string) $folder['path_cache'];
        $parentPath = str_contains($oldPath, '/') ? substr($oldPath, 0, (int) strrpos($oldPath, '/')) : '';
        $newPath = $parentPath === '' ? $newName : $parentPath . '/' . $newName;
        $ids = $this->descendantIds((int) $folder['id']);
        $params = ['newPath' => $newPath, 'oldLen' => mb_strlen($oldPath)];
        $in = $this->inClause($ids, $params);
        $this->exec('UPDATE folders SET name = ?, updated_at = ? WHERE id = ?', [$newName, self::now(), (int) $folder['id']]);
        $this->exec(
            "UPDATE folders SET path_cache = LEFT(CONCAT(:newPath, SUBSTRING(path_cache, :oldLen + 1)), 1024) WHERE id IN ($in)",
            $params,
        );
    }

    public function updateDescription(int $id, ?string $description): void
    {
        $this->exec('UPDATE folders SET description = ?, updated_at = ? WHERE id = ?', [$description, self::now(), $id]);
    }

    public function touch(int $id): void
    {
        $this->exec('UPDATE folders SET updated_at = ? WHERE id = ?', [self::now(), $id]);
    }

    /**
     * Carpetas raíz a las que el usuario tiene acceso (o todas si $userId es null), con estadísticas.
     *
     * @return list<array<string, mixed>>
     */
    public function rootsWithStats(?int $userId): array
    {
        $params = [];
        if ($userId !== null) {
            $sql = 'SELECT f.id, f.uuid, f.name, f.description, f.owner_id, f.created_at, f.updated_at,
                    m.permission, o.name AS owner_name, o.email AS owner_email, oe.name AS owner_entity
                FROM folder_members m
                JOIN folders f ON f.id = m.folder_id AND f.deleted_at IS NULL AND f.parent_id IS NULL
                JOIN users o ON o.id = f.owner_id
                LEFT JOIN entities oe ON oe.id = o.entity_id
                WHERE m.user_id = :uid ORDER BY f.name';
            $params['uid'] = $userId;
        } else {
            $sql = "SELECT f.id, f.uuid, f.name, f.description, f.owner_id, f.created_at, f.updated_at,
                    'owner' AS permission, o.name AS owner_name, o.email AS owner_email, oe.name AS owner_entity
                FROM folders f
                JOIN users o ON o.id = f.owner_id
                LEFT JOIN entities oe ON oe.id = o.entity_id
                WHERE f.deleted_at IS NULL AND f.parent_id IS NULL ORDER BY f.name";
        }
        $roots = $this->all($sql, $params);
        if ($roots === []) {
            return [];
        }
        $stats = $this->rootStats(array_map(static fn (array $r): int => (int) $r['id'], $roots));
        foreach ($roots as &$root) {
            $s = $stats[(int) $root['id']] ?? ['files_count' => 0, 'size_bytes' => 0, 'last_file_at' => null];
            $root['files_count'] = (int) $s['files_count'];
            $root['size_bytes'] = (int) $s['size_bytes'];
            $root['last_activity_at'] = max((string) $root['updated_at'], (string) ($s['last_file_at'] ?? ''));
        }

        return $roots;
    }

    /**
     * @param list<int> $rootIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function rootStats(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }
        $params = [];
        $in = $this->inClause($rootIds, $params);
        $rows = $this->all(
            "SELECT fo.root_id, COUNT(fi.id) AS files_count, COALESCE(SUM(fi.size_bytes), 0) AS size_bytes,
                    MAX(fi.created_at) AS last_file_at
             FROM files fi JOIN folders fo ON fo.id = fi.folder_id
             WHERE fo.root_id IN ($in) AND fi.status = 'ready' AND fi.deleted_at IS NULL AND fo.deleted_at IS NULL
             GROUP BY fo.root_id",
            $params,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['root_id']] = $row;
        }

        return $out;
    }

    /**
     * Bytes ocupados (listos + en subida) en una carpeta raíz, para validar la cuota.
     */
    public function rootUsageBytes(int $rootId): int
    {
        return (int) $this->scalar(
            "SELECT COALESCE(SUM(fi.size_bytes), 0) FROM files fi JOIN folders fo ON fo.id = fi.folder_id
             WHERE fo.root_id = ? AND fi.deleted_at IS NULL AND fi.status IN ('ready', 'uploading')",
            [$rootId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(?int $userId, string $query, int $limit = 15): array
    {
        $params = ['q' => '%' . self::escapeLike($query) . '%'];
        $access = '';
        if ($userId !== null) {
            $access = 'AND f.root_id IN (SELECT folder_id FROM folder_members WHERE user_id = :uid)';
            $params['uid'] = $userId;
        }

        return $this->all(
            "SELECT f.uuid, f.name, f.path_cache, f.updated_at, r.uuid AS root_uuid
             FROM folders f JOIN folders r ON r.id = f.root_id
             WHERE f.deleted_at IS NULL AND f.name LIKE :q $access
             ORDER BY f.updated_at DESC LIMIT $limit",
            $params,
        );
    }

    /**
     * Uso de almacenamiento por carpeta raíz (panel de administración).
     *
     * @return list<array<string, mixed>>
     */
    public function storageUsage(): array
    {
        return $this->all(
            "SELECT r.uuid, r.name, r.created_at, o.name AS owner_name, o.email AS owner_email, oe.name AS owner_entity,
                    COUNT(fi.id) AS files_count, COALESCE(SUM(fi.size_bytes), 0) AS size_bytes,
                    (SELECT COUNT(*) FROM folder_members m WHERE m.folder_id = r.id) AS members_count
             FROM folders r
             JOIN users o ON o.id = r.owner_id
             LEFT JOIN entities oe ON oe.id = o.entity_id
             LEFT JOIN folders fo ON fo.root_id = r.id AND fo.deleted_at IS NULL
             LEFT JOIN files fi ON fi.folder_id = fo.id AND fi.deleted_at IS NULL AND fi.status = 'ready'
             WHERE r.parent_id IS NULL AND r.deleted_at IS NULL
             GROUP BY r.id, r.uuid, r.name, r.created_at, o.name, o.email, oe.name
             ORDER BY size_bytes DESC",
        );
    }
}
