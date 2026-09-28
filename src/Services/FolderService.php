<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\FileRepository;
use App\Repositories\FolderMemberRepository;
use App\Repositories\FolderRepository;
use App\Support\FileName;
use App\Support\Present;
use App\Support\RequestContext;
use App\Support\Uuid;

/**
 * Carpetas y subcarpetas: creación, renombrado, borrado, listado y búsqueda.
 */
final class FolderService
{
    public function __construct(
        private readonly FolderRepository $folders,
        private readonly FileRepository $files,
        private readonly FolderMemberRepository $members,
        private readonly AccessService $access,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $uuid): array
    {
        $folder = Uuid::isValid($uuid) ? $this->folders->findByUuid($uuid) : null;
        if ($folder === null) {
            throw new NotFoundException('No encontramos esta carpeta. Puede que se haya eliminado.');
        }

        return $folder;
    }

    /**
     * Carpeta + verificación de permiso mínimo.
     *
     * @param array<string, mixed> $user
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function getWithAccess(array $user, string $uuid, string $minimum): array
    {
        $folder = $this->get($uuid);
        $permission = $this->access->require($user, $folder, $minimum);

        return [$folder, $permission];
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function canCreateRoot(array $user): bool
    {
        return in_array($user['role'] ?? '', ['admin', 'user'], true);
    }

    public static function cleanName(string $name, string $what = 'la carpeta'): string
    {
        $clean = FileName::sanitize($name);
        if ($clean === '') {
            throw new ValidationException(sprintf('Escribe un nombre válido para %s.', $what), 'invalid_name', ['fields' => ['name' => 'Nombre no válido.']]);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function createRoot(array $user, string $name, ?string $description, RequestContext $ctx): array
    {
        if (!self::canCreateRoot($user)) {
            throw new ForbiddenException('Tu cuenta es de invitado externo: puedes trabajar en las carpetas que te compartan, pero no crear carpetas nuevas. Si lo necesitas, pídelo al administrador.', 'external_account');
        }
        $name = self::cleanName($name);
        $description = $description !== null ? mb_substr(trim($description), 0, 1000) : null;
        foreach ($this->folders->rootsWithStats((int) $user['id']) as $root) {
            if ((int) $root['owner_id'] === (int) $user['id'] && mb_strtolower((string) $root['name']) === mb_strtolower($name)) {
                throw new ConflictException(sprintf('Ya tienes una carpeta llamada "%s". Elige otro nombre.', $name), 'name_conflict');
            }
        }
        $uuid = Uuid::v4();
        $id = $this->folders->transaction(function () use ($uuid, $user, $name, $description): int {
            $id = $this->folders->createRoot($uuid, (int) $user['id'], $name, $description ?: null);
            $this->members->add($id, (int) $user['id'], 'owner', (int) $user['id']);

            return $id;
        });
        $this->audit->log('folder_create', (int) $user['id'], 'folder', $id, ['name' => $name], $ctx);

        return Present::folder((array) $this->folders->find($id)) + ['permission' => 'owner'];
    }

    /**
     * Crea una subcarpeta o una ruta completa ("A/B/C") de forma idempotente.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function createSubfolder(array $user, string $parentUuid, string $nameOrPath, RequestContext $ctx, bool $failIfExists = true): array
    {
        [$parent] = $this->getWithAccess($user, $parentUuid, 'editor');
        $segments = FileName::directorySegments($nameOrPath);
        if ($segments === []) {
            throw new ValidationException('Escribe un nombre válido para la carpeta.', 'invalid_name', ['fields' => ['name' => 'Nombre no válido.']]);
        }
        if ($failIfExists && count($segments) === 1 && $this->folders->findChildByName((int) $parent['id'], $segments[0]) !== null) {
            throw new ConflictException(sprintf('Ya existe una carpeta llamada "%s" aquí.', $segments[0]), 'name_conflict');
        }
        $folder = $this->ensurePath($parent, $segments, $user);
        if ((int) $folder['id'] !== (int) $parent['id']) {
            $this->audit->log('folder_create', (int) $user['id'], 'folder', (int) $folder['id'], ['path' => implode('/', $segments)], $ctx);
        }

        return Present::folder($folder);
    }

    /**
     * Garantiza que exista la cadena de subcarpetas bajo $parent. Seguro ante concurrencia.
     *
     * @param array<string, mixed> $parent
     * @param list<string> $segments
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function ensurePath(array $parent, array $segments, array $user): array
    {
        $current = $parent;
        foreach ($segments as $segment) {
            $child = $this->folders->findChildByName((int) $current['id'], $segment);
            if ($child === null) {
                try {
                    $id = $this->folders->createChild(Uuid::v4(), $current, (int) $user['id'], $segment);
                    $child = $this->folders->find($id);
                } catch (\PDOException $e) {
                    // Otra petición concurrente la creó primero (índice único).
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    $child = $this->folders->findChildByName((int) $current['id'], $segment);
                }
                if ($child === null) {
                    throw new \RuntimeException('No se pudo crear la subcarpeta ' . $segment);
                }
            }
            $current = $child;
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function update(array $user, string $uuid, ?string $name, ?string $description, RequestContext $ctx): array
    {
        $folder = $this->get($uuid);
        $isRoot = $folder['parent_id'] === null;
        $this->access->require($user, $folder, $isRoot ? 'owner' : 'editor');
        if ($name !== null) {
            $name = self::cleanName($name);
            if ($name !== $folder['name']) {
                if (!$isRoot) {
                    $sibling = $this->folders->findChildByName((int) $folder['parent_id'], $name);
                    if ($sibling !== null && (int) $sibling['id'] !== (int) $folder['id']) {
                        throw new ConflictException(sprintf('Ya existe una carpeta llamada "%s" aquí.', $name), 'name_conflict');
                    }
                }
                $this->folders->transaction(fn () => $this->folders->rename($folder, $name));
                $this->audit->log('folder_rename', (int) $user['id'], 'folder', (int) $folder['id'], ['from' => $folder['name'], 'to' => $name], $ctx);
            }
        }
        if ($description !== null && $isRoot) {
            $this->folders->updateDescription((int) $folder['id'], mb_substr(trim($description), 0, 1000) ?: null);
        }

        return Present::folder((array) $this->folders->find((int) $folder['id']));
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array{parentUuid: string|null}
     */
    public function delete(array $user, string $uuid, RequestContext $ctx): array
    {
        $folder = $this->get($uuid);
        $isRoot = $folder['parent_id'] === null;
        $this->access->require($user, $folder, $isRoot ? 'owner' : 'editor');
        $parentUuid = null;
        if (!$isRoot) {
            $parent = $this->folders->find((int) $folder['parent_id']);
            $parentUuid = $parent['uuid'] ?? null;
        }
        $count = $this->folders->transaction(fn () => $this->folders->softDeleteTree((int) $folder['id']));
        $this->audit->log('folder_delete', (int) $user['id'], 'folder', (int) $folder['id'], ['name' => $folder['name'], 'folders' => $count], $ctx);

        return ['parentUuid' => is_string($parentUuid) ? $parentUuid : null];
    }

    /**
     * Borrado múltiple de archivos y subcarpetas dentro de una carpeta.
     *
     * @param array<string, mixed> $user
     * @param list<string> $fileUuids
     * @param list<string> $folderUuids
     *
     * @return array{files: int, folders: int}
     */
    public function deleteItems(array $user, string $folderUuid, array $fileUuids, array $folderUuids, RequestContext $ctx): array
    {
        [$folder] = $this->getWithAccess($user, $folderUuid, 'editor');
        $fileUuids = array_values(array_filter($fileUuids, [Uuid::class, 'isValid']));
        $folderUuids = array_values(array_filter($folderUuids, [Uuid::class, 'isValid']));
        $files = $this->files->findReadyByUuids($fileUuids, [(int) $folder['id']]);
        $deletedFolders = 0;
        $this->folders->transaction(function () use ($files, $folderUuids, $folder, &$deletedFolders): void {
            $this->files->softDelete(array_map(static fn (array $f): int => (int) $f['id'], $files));
            foreach ($folderUuids as $uuid) {
                $child = $this->folders->findByUuid($uuid);
                if ($child !== null && (int) $child['parent_id'] === (int) $folder['id']) {
                    $this->folders->softDeleteTree((int) $child['id']);
                    $deletedFolders++;
                }
            }
        });
        foreach ($files as $file) {
            $this->audit->log('delete', (int) $user['id'], 'file', (int) $file['id'], ['name' => $file['name']], $ctx);
        }
        if ($deletedFolders > 0) {
            $this->audit->log('folder_delete', (int) $user['id'], 'folder', (int) $folder['id'], ['subfolders' => $deletedFolders], $ctx);
        }

        return ['files' => count($files), 'folders' => $deletedFolders];
    }

    /**
     * Contenido de una carpeta para la vista principal.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function contents(array $user, string $uuid): array
    {
        [$folder, $permission] = $this->getWithAccess($user, $uuid, 'viewer');

        return $this->describeContents($folder, $permission);
    }

    /**
     * @param array<string, mixed> $folder
     *
     * @return array<string, mixed>
     */
    public function describeContents(array $folder, ?string $permission, ?int $breadcrumbRootId = null): array
    {
        $isRoot = $folder['parent_id'] === null;
        $breadcrumb = $this->folders->breadcrumb((int) $folder['id']);
        if ($breadcrumbRootId !== null) {
            // Para enlaces públicos: recortar la ruta a partir de la carpeta compartida.
            $index = array_search($breadcrumbRootId, array_map(static fn (array $b): int => (int) $b['id'], $breadcrumb), true);
            $breadcrumb = $index === false ? [] : array_slice($breadcrumb, (int) $index);
        }
        $root = $isRoot ? $folder : ($this->folders->find((int) $folder['root_id']) ?? $folder);

        return [
            'folder' => Present::folder($folder) + [
                'isRoot' => $isRoot,
                'rootUuid' => $root['uuid'],
                'rootName' => $root['name'],
                'ownerName' => $root['owner_name'] ?? null,
                'ownerEntity' => $root['owner_entity'] ?? null,
                'description' => $root['description'] ?? null,
            ],
            'permission' => $permission,
            'capabilities' => AccessService::capabilities($permission, $isRoot),
            'breadcrumb' => array_map(static fn (array $b): array => ['uuid' => $b['uuid'], 'name' => $b['name']], $breadcrumb),
            'folders' => array_map([Present::class, 'folder'], $this->folders->children((int) $folder['id'])),
            'files' => array_map([Present::class, 'file'], $this->files->listReady((int) $folder['id'])),
        ];
    }

    /**
     * Pantalla de inicio: "Mis carpetas" y "Compartidas conmigo".
     *
     * @param array<string, mixed> $user
     *
     * @return array{mine: list<array<string, mixed>>, shared: list<array<string, mixed>>}
     */
    public function home(array $user): array
    {
        $mine = [];
        $shared = [];
        foreach ($this->folders->rootsWithStats((int) $user['id']) as $root) {
            $dto = Present::folder($root);
            if ((int) $root['owner_id'] === (int) $user['id']) {
                $mine[] = $dto;
            } else {
                $shared[] = $dto;
            }
        }
        $byActivity = static fn (array $a, array $b): int => strcmp((string) ($b['lastActivityAt'] ?? ''), (string) ($a['lastActivityAt'] ?? ''));
        usort($mine, $byActivity);
        usort($shared, $byActivity);

        return ['mine' => $mine, 'shared' => $shared];
    }

    /**
     * Búsqueda global de carpetas y archivos accesibles.
     *
     * @param array<string, mixed> $user
     *
     * @return array{folders: list<array<string, mixed>>, files: list<array<string, mixed>>}
     */
    public function search(array $user, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['folders' => [], 'files' => []];
        }
        $userId = ($user['role'] ?? '') === 'admin' ? null : (int) $user['id'];

        return [
            'folders' => array_map(static fn (array $f): array => [
                'uuid' => $f['uuid'],
                'name' => $f['name'],
                'path' => $f['path_cache'],
            ], $this->folders->search($userId, $query)),
            'files' => array_map(static fn (array $f): array => [
                'uuid' => $f['uuid'],
                'name' => $f['name'],
                'kind' => Present::kind((string) $f['extension']),
                'size' => (int) $f['size_bytes'],
                'folderUuid' => $f['folder_uuid'],
                'path' => $f['folder_path'],
            ], $this->files->search($userId, $query)),
        ];
    }
}
