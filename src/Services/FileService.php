<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AuditLogRepository;
use App\Repositories\FileRepository;
use App\Repositories\FolderRepository;
use App\Support\FileName;
use App\Support\Present;
use App\Support\RequestContext;
use App\Support\Uuid;

/**
 * Operaciones sobre archivos existentes: detalle, renombrar, eliminar, descargar y previsualizar.
 */
final class FileService
{
    public function __construct(
        private readonly FileRepository $files,
        private readonly FolderRepository $folders,
        private readonly AuditLogRepository $auditLog,
        private readonly AccessService $access,
        private readonly S3Service $s3,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function getWithAccess(array $user, string $uuid, string $minimum, bool $allowVersions = false): array
    {
        $file = null;
        if (Uuid::isValid($uuid)) {
            $file = $allowVersions ? $this->files->findVersionByUuid($uuid) : $this->files->findByUuid($uuid);
        }
        if ($file === null || ($file['status'] !== 'ready')) {
            throw new NotFoundException('No encontramos este archivo. Puede que se haya eliminado.');
        }
        $folder = $this->folders->find((int) $file['folder_id']);
        if ($folder === null) {
            throw new NotFoundException('No encontramos este archivo. Puede que se haya eliminado.');
        }
        $permission = $this->access->require($user, $folder, $minimum);

        return [$file, $permission];
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function details(array $user, string $uuid): array
    {
        [$file, $permission] = $this->getWithAccess($user, $uuid, 'viewer', true);
        $canSeeDownloads = AccessService::allows($permission, 'editor');
        $versions = array_map(static fn (array $v): array => [
            'uuid' => $v['uuid'],
            'name' => $v['name'],
            'size' => (int) $v['size_bytes'],
            'version' => (int) $v['version'],
            'uploadedBy' => $v['uploader_name'],
            'createdAt' => Present::iso($v['created_at']),
            'current' => (int) $v['depth'] === 0,
        ], $this->files->versions((int) $file['id']));

        return [
            'file' => Present::file($file) + [
                'folderUuid' => $file['folder_uuid'],
                'folderPath' => $file['folder_path'],
                'uploaderEmail' => $canSeeDownloads ? $file['uploader_email'] : null,
                'etag' => $file['etag'],
            ],
            'versions' => $versions,
            'downloads' => $canSeeDownloads ? array_map(static fn (array $d): array => [
                'at' => Present::iso($d['created_at']),
                'action' => $d['action'],
                'user' => $d['user_name'] ?? ((bool) $d['via_link'] ? 'Enlace público' : 'Desconocido'),
                'email' => $d['user_email'],
                'viaLink' => (bool) $d['via_link'],
            ], $this->auditLog->downloadsOfFile((int) $file['id'])) : null,
            'permission' => $permission,
        ];
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function rename(array $user, string $uuid, string $newName, RequestContext $ctx): array
    {
        [$file] = $this->getWithAccess($user, $uuid, 'editor');
        $name = FileName::sanitize($newName);
        if ($name === '') {
            throw new ValidationException('Escribe un nombre válido para el archivo.', 'invalid_name');
        }
        if ($name === $file['name']) {
            return Present::file($file);
        }
        if ($this->files->nameTaken((int) $file['folder_id'], $name, (int) $file['id'])) {
            throw new ConflictException(sprintf('Ya existe un archivo llamado "%s" en esta carpeta.', $name), 'name_conflict');
        }
        try {
            $this->files->update((int) $file['id'], ['name' => $name, 'extension' => FileName::extension($name)]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new ConflictException(sprintf('Ya existe un archivo llamado "%s" en esta carpeta.', $name), 'name_conflict');
            }
            throw $e;
        }
        $this->audit->log('rename', (int) $user['id'], 'file', (int) $file['id'], ['from' => $file['name'], 'to' => $name], $ctx);

        return Present::file((array) $this->files->find((int) $file['id']));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function delete(array $user, string $uuid, RequestContext $ctx): void
    {
        [$file] = $this->getWithAccess($user, $uuid, 'editor');
        $this->files->softDelete([(int) $file['id']]);
        $this->folders->touch((int) $file['folder_id']);
        $this->audit->log('delete', (int) $user['id'], 'file', (int) $file['id'], ['name' => $file['name']], $ctx);
    }

    /**
     * URL prefirmada de descarga (el navegador descarga directo desde S3).
     *
     * @param array<string, mixed> $user
     */
    public function downloadUrl(array $user, string $uuid, RequestContext $ctx): string
    {
        [$file] = $this->getWithAccess($user, $uuid, 'viewer', true);
        $url = $this->presignedDownload($file);
        $this->audit->log('download', (int) $user['id'], 'file', (int) $file['id'], ['name' => $file['name'], 'version' => (int) $file['version']], $ctx);

        return $url;
    }

    /**
     * @param array<string, mixed> $file
     */
    public function presignedDownload(array $file): string
    {
        return $this->s3->presignGet((string) $file['s3_key'], (string) $file['name']);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function preview(array $user, string $uuid, RequestContext $ctx): array
    {
        [$file] = $this->getWithAccess($user, $uuid, 'viewer', true);
        $info = $this->previewInfo($file);
        $this->audit->log('preview', (int) $user['id'], 'file', (int) $file['id'], ['name' => $file['name']], $ctx);

        return $info;
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    public function previewInfo(array $file): array
    {
        $ext = (string) $file['extension'];
        $kind = FileName::previewKind((string) $file['mime_type'], $ext);
        if ($kind === null) {
            throw new ValidationException('Este tipo de archivo no se puede previsualizar. Descárgalo para abrirlo.', 'no_preview');
        }
        $mime = FileName::previewMime($kind, (string) $file['mime_type'], $ext);

        return [
            'kind' => $kind,
            'url' => $this->s3->presignGet((string) $file['s3_key'], (string) $file['name'], true, $mime),
            'downloadUrl' => null,
            'name' => $file['name'],
            'size' => (int) $file['size_bytes'],
            'mime' => $mime,
        ];
    }
}
