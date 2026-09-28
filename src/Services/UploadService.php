<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\FileRepository;
use App\Repositories\FolderRepository;
use App\Repositories\Repository;
use App\Repositories\UploadSessionRepository;
use App\Support\Bytes;
use App\Support\Config;
use App\Support\FileName;
use App\Support\PartSize;
use App\Support\Present;
use App\Support\RequestContext;
use App\Support\Uuid;

/**
 * Protocolo de subida directa navegador -> S3 con URLs prefirmadas.
 *
 *  init  -> single (PUT prefirmado) o multipart (CreateMultipartUpload)
 *  sign  -> URLs prefirmadas por parte (lotes de hasta 20)
 *  complete / confirm -> verificación con HeadObject y status = ready
 */
final class UploadService
{
    public const MAX_SIGN_BATCH = 20;
    private const SESSION_TTL = '+24 hours';

    public function __construct(
        private readonly FolderService $folderService,
        private readonly FolderRepository $folders,
        private readonly FileRepository $files,
        private readonly UploadSessionRepository $sessions,
        private readonly S3Service $s3,
        private readonly SettingsService $settings,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input {name, size, mime, relativePath, onConflict}
     *
     * @return array<string, mixed>
     */
    public function init(array $user, string $folderUuid, array $input, RequestContext $ctx): array
    {
        [$folder] = $this->folderService->getWithAccess($user, $folderUuid, 'editor');
        $this->rateLimiter->hit('upload:user:' . $user['id'], 3000, 60, 'Demasiadas solicitudes de subida por minuto. La cola se reanudará en breve.');

        $originalName = is_string($input['name'] ?? null) ? $input['name'] : '';
        $name = FileName::sanitize($originalName);
        if ($name === '') {
            throw new ValidationException('El nombre del archivo no es válido.', 'invalid_name');
        }
        $size = $input['size'] ?? null;
        if (!is_int($size) && !(is_string($size) && ctype_digit($size)) && !(is_float($size) && floor($size) === $size)) {
            throw new ValidationException(sprintf('No pudimos leer el tamaño de "%s".', $name), 'invalid_size');
        }
        $size = (int) $size;
        if ($size < 0) {
            throw new ValidationException(sprintf('No pudimos leer el tamaño de "%s".', $name), 'invalid_size');
        }
        $extension = FileName::extension($name);
        if ($this->settings->isBlocked($extension)) {
            throw new ValidationException(
                sprintf('No se permite subir archivos .%s por seguridad. Si necesitas compartir "%s", comprímelo en un .zip.', $extension, $name),
                'blocked_extension',
            );
        }
        $maxFile = $this->settings->maxFileBytes();
        if ($size > $maxFile) {
            throw new LimitExceededException(
                sprintf('No se pudo subir "%s" porque supera el límite de %s por archivo. Puedes comprimirlo o dividirlo.', $name, Bytes::human($maxFile)),
                'file_too_large',
            );
        }

        $relativePath = is_string($input['relativePath'] ?? null) ? $input['relativePath'] : '';
        $segments = FileName::directorySegments($relativePath, $originalName);
        $target = $segments === [] ? $folder : $this->folderService->ensurePath($folder, $segments, $user);
        $rootId = (int) $target['root_id'];

        $folderMax = $this->settings->folderMaxBytes();
        if ($this->folders->rootUsageBytes($rootId) + $size > $folderMax) {
            throw new LimitExceededException(
                sprintf('No hay espacio para "%s": la carpeta alcanzó su límite de %s. Elimina archivos que ya no necesites o pide ayuda al administrador.', $name, Bytes::human($folderMax)),
                'folder_quota_exceeded',
            );
        }

        $onConflict = is_string($input['onConflict'] ?? null) ? $input['onConflict'] : null;
        $previous = null;
        $version = 1;
        $existing = $this->files->findLiveByName((int) $target['id'], $name);
        if ($existing !== null) {
            switch ($onConflict) {
                case 'skip':
                    return ['mode' => 'skipped', 'name' => $name];
                case 'keep_both':
                    $name = $this->uniqueName((int) $target['id'], $name);
                    break;
                case 'replace':
                    if ($existing['status'] !== 'ready') {
                        throw new ConflictException(sprintf('"%s" se está subiendo en este momento. Espera a que termine.', $name), 'upload_in_progress');
                    }
                    $previous = $existing;
                    $version = (int) $existing['version'] + 1;
                    break;
                default:
                    throw new ConflictException(sprintf('Ya existe un archivo llamado "%s" en esta carpeta.', $name), 'name_conflict', [
                        'existing' => [
                            'name' => $existing['name'],
                            'size' => (int) $existing['size_bytes'],
                            'createdAt' => Present::iso($existing['created_at']),
                            'status' => $existing['status'],
                        ],
                        'folder' => $target['name'],
                    ]);
            }
        }

        $root = $target['parent_id'] === null ? $target : (array) $this->folders->find($rootId);
        $fileUuid = Uuid::v4();
        $key = $this->s3->key((string) $root['uuid'], $fileUuid);
        $mime = FileName::normalizeMime(is_string($input['mime'] ?? null) ? $input['mime'] : '');
        $now = Repository::now();
        $fileId = $this->files->create([
            'uuid' => $fileUuid,
            'folder_id' => (int) $target['id'],
            'uploaded_by' => (int) $user['id'],
            'name' => $name,
            'extension' => $extension,
            'mime_type' => $mime,
            'size_bytes' => $size,
            's3_key' => $key,
            'status' => 'uploading',
            'version' => $version,
            'previous_version_id' => $previous !== null ? (int) $previous['id'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $base = [
            'fileUuid' => $fileUuid,
            'name' => $name,
            'folderUuid' => $target['uuid'],
            'replacing' => $previous !== null,
        ];
        try {
            if ($size <= $this->settings->singleMaxBytes()) {
                $put = $this->s3->presignPut($key, $mime, $name);

                return $base + ['mode' => 'single', 'url' => $put['url'], 'headers' => $put['headers'], 'expiresIn' => $put['expiresIn']];
            }
            $plan = PartSize::calculate($size, $this->settings->partSizeBytes());
            $uploadId = $this->s3->createMultipart($key, $mime, $name);
            $sessionUuid = Uuid::v4();
            $this->sessions->create([
                'uuid' => $sessionUuid,
                'file_id' => $fileId,
                'user_id' => (int) $user['id'],
                's3_upload_id' => $uploadId,
                'part_size' => $plan['partSize'],
                'total_parts' => $plan['totalParts'],
                'completed_parts' => json_encode([]),
                'status' => 'active',
                'expires_at' => Repository::now(self::SESSION_TTL),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $base + [
                'mode' => 'multipart',
                'uploadSessionUuid' => $sessionUuid,
                'partSize' => $plan['partSize'],
                'totalParts' => $plan['totalParts'],
            ];
        } catch (\Throwable $e) {
            $this->files->update($fileId, ['status' => 'failed']);
            throw $e;
        }
    }

    private function uniqueName(int $folderId, string $name): string
    {
        for ($n = 2; $n < 10000; $n++) {
            $candidate = FileName::withCounter($name, $n);
            if (!$this->files->nameTaken($folderId, $candidate)) {
                return $candidate;
            }
        }

        return FileName::withCounter($name, random_int(10000, 99999));
    }

    /**
     * Nueva URL PUT para una subida simple (si la anterior expiró).
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function signSingle(array $user, string $fileUuid): array
    {
        $file = $this->uploadingFile($user, $fileUuid);
        if ((int) $file['size_bytes'] > $this->settings->singleMaxBytes()) {
            throw new ValidationException('Este archivo se sube por partes.', 'wrong_mode');
        }
        $put = $this->s3->presignPut((string) $file['s3_key'], (string) $file['mime_type'], (string) $file['name']);

        return ['url' => $put['url'], 'headers' => $put['headers'], 'expiresIn' => $put['expiresIn']];
    }

    /**
     * @param array<string, mixed> $user
     * @param list<mixed> $partNumbers
     *
     * @return array<string, mixed>
     */
    public function signParts(array $user, string $sessionUuid, array $partNumbers): array
    {
        $session = $this->activeSession($user, $sessionUuid);
        $this->rateLimiter->hit('sign:user:' . $user['id'], 2000, 60, 'Demasiadas solicitudes de firma por minuto. La subida continuará en breve.');
        $numbers = array_values(array_unique(array_map('intval', $partNumbers)));
        if ($numbers === [] || count($numbers) > self::MAX_SIGN_BATCH) {
            throw new ValidationException(sprintf('Solicita entre 1 y %d partes por lote.', self::MAX_SIGN_BATCH), 'invalid_parts');
        }
        $urls = [];
        foreach ($numbers as $n) {
            if ($n < 1 || $n > (int) $session['total_parts']) {
                throw new ValidationException('Número de parte fuera de rango.', 'invalid_parts');
            }
            $urls[] = ['partNumber' => $n, 'url' => $this->s3->presignPart((string) $session['s3_key'], (string) $session['s3_upload_id'], $n)];
        }

        return ['parts' => $urls, 'expiresIn' => $this->config->int('s3.upload_ttl', 1800)];
    }

    /**
     * Estado de una subida multipart para reanudarla tras recargar la página.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function status(array $user, string $sessionUuid): array
    {
        $session = $this->session($user, $sessionUuid);
        $parts = [];
        if ($session['status'] === 'active') {
            $parts = $this->s3->listParts((string) $session['s3_key'], (string) $session['s3_upload_id']);
        }

        return [
            'uploadSessionUuid' => $session['uuid'],
            'fileUuid' => $session['file_uuid'],
            'name' => $session['file_name'],
            'size' => (int) $session['size_bytes'],
            'status' => $session['status'],
            'fileStatus' => $session['file_status'],
            'partSize' => (int) $session['part_size'],
            'totalParts' => (int) $session['total_parts'],
            'parts' => $parts,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param list<mixed> $parts
     *
     * @return array<string, mixed>
     */
    public function complete(array $user, string $sessionUuid, array $parts, RequestContext $ctx): array
    {
        $session = $this->activeSession($user, $sessionUuid);
        $total = (int) $session['total_parts'];
        $clean = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $n = (int) ($part['partNumber'] ?? 0);
            $etag = is_string($part['etag'] ?? null) ? trim($part['etag']) : '';
            if ($n >= 1 && $n <= $total && $etag !== '') {
                $clean[$n] = ['partNumber' => $n, 'etag' => $etag];
            }
        }
        ksort($clean);
        $clean = array_values($clean);
        if (count($clean) !== $total) {
            // Completar con lo que S3 ya tiene (p. ej. partes subidas antes de recargar).
            $known = [];
            foreach ($this->s3->listParts((string) $session['s3_key'], (string) $session['s3_upload_id']) as $p) {
                $known[$p['partNumber']] = ['partNumber' => $p['partNumber'], 'etag' => $p['etag']];
            }
            foreach ($clean as $p) {
                $known[$p['partNumber']] = $p;
            }
            ksort($known);
            $clean = array_values($known);
            if (count($clean) !== $total) {
                throw new ValidationException(sprintf('Faltan %d partes del archivo por subir.', $total - count($clean)), 'parts_missing');
            }
        }
        $this->sessions->saveCompletedParts((int) $session['id'], $clean);
        $etag = $this->s3->completeMultipart((string) $session['s3_key'], (string) $session['s3_upload_id'], $clean);
        $this->sessions->setStatus((int) $session['id'], 'completed');
        $file = (array) $this->files->find((int) $session['file_id']);

        return $this->finalize($user, $file, $etag, $ctx);
    }

    /**
     * Confirma una subida simple.
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function confirm(array $user, string $fileUuid, RequestContext $ctx): array
    {
        $file = $this->files->findByUuid($fileUuid);
        if ($file !== null && $file['status'] === 'ready' && (int) $file['uploaded_by'] === (int) $user['id']) {
            return Present::file($file); // Idempotente.
        }
        $file = $this->uploadingFile($user, $fileUuid);

        return $this->finalize($user, $file, null, $ctx);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private function finalize(array $user, array $file, ?string $etag, RequestContext $ctx): array
    {
        $head = $this->s3->head((string) $file['s3_key']);
        if ($head === null) {
            throw new ValidationException(sprintf('"%s" no llegó al almacenamiento. Vuelve a intentarlo.', $file['name']), 'upload_missing');
        }
        if ($head['size'] !== (int) $file['size_bytes']) {
            $this->files->update((int) $file['id'], ['status' => 'failed']);
            $this->s3->delete((string) $file['s3_key']);
            throw new ValidationException(sprintf('"%s" llegó incompleto (%s de %s). Vuelve a subirlo.', $file['name'], Bytes::human($head['size']), Bytes::human((int) $file['size_bytes'])), 'size_mismatch');
        }
        $etag ??= $head['etag'];

        $this->files->transaction(function () use ($file, $etag): void {
            if ($file['previous_version_id'] !== null) {
                $previous = $this->files->find((int) $file['previous_version_id']);
                if ($previous !== null && $previous['deleted_at'] === null) {
                    $now = Repository::now();
                    $this->files->update((int) $previous['id'], ['deleted_at' => $now, 'superseded_at' => $now]);
                }
            }
            try {
                $this->files->update((int) $file['id'], ['status' => 'ready', 'etag' => $etag]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                // Otro archivo con el mismo nombre terminó antes: conservar ambos.
                $this->files->update((int) $file['id'], [
                    'status' => 'ready',
                    'etag' => $etag,
                    'name' => $this->uniqueName((int) $file['folder_id'], (string) $file['name']),
                ]);
            }
        });
        $this->folders->touch((int) $file['folder_id']);
        $fresh = (array) $this->files->find((int) $file['id']);
        $this->audit->log('upload', (int) $user['id'], 'file', (int) $file['id'], [
            'name' => $fresh['name'],
            'size' => (int) $fresh['size_bytes'],
            'version' => (int) $fresh['version'],
        ], $ctx);

        return Present::file($fresh) + ['folderUuid' => $fresh['folder_uuid']];
    }

    /**
     * Cancela una subida multipart.
     *
     * @param array<string, mixed> $user
     */
    public function abort(array $user, string $sessionUuid, RequestContext $ctx): void
    {
        $session = $this->session($user, $sessionUuid);
        if ($session['status'] === 'active') {
            $this->s3->abortMultipart((string) $session['s3_key'], (string) $session['s3_upload_id']);
            $this->sessions->setStatus((int) $session['id'], 'aborted');
        }
        if ($session['file_status'] === 'uploading') {
            $this->files->update((int) $session['file_id'], ['status' => 'failed']);
        }
        $this->audit->log('upload_cancel', (int) $user['id'], 'file', (int) $session['file_id'], ['name' => $session['file_name']], $ctx);
    }

    /**
     * Cancela una subida simple.
     *
     * @param array<string, mixed> $user
     */
    public function abortSingle(array $user, string $fileUuid): void
    {
        $file = $this->files->findByUuid($fileUuid);
        if ($file === null || $file['status'] !== 'uploading') {
            return;
        }
        if ((int) $file['uploaded_by'] !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
            throw new ForbiddenException();
        }
        $session = $this->sessions->activeForFile((int) $file['id']);
        if ($session !== null) {
            $this->s3->abortMultipart((string) $file['s3_key'], (string) $session['s3_upload_id']);
            $this->sessions->setStatus((int) $session['id'], 'aborted');
        }
        $this->files->update((int) $file['id'], ['status' => 'failed']);
    }

    /**
     * Notifica a los miembros que se subieron N archivos (máximo un correo cada 10 minutos por carpeta).
     *
     * @param array<string, mixed> $user
     */
    public function notifyUploaded(array $user, string $folderUuid, int $count): bool
    {
        [$folder] = $this->folderService->getWithAccess($user, $folderUuid, 'editor');
        if ($count < 1) {
            return false;
        }
        try {
            $this->rateLimiter->hit('notify:folder:' . $folder['root_id'] . ':' . $user['id'], 1, 600);
        } catch (\App\Exceptions\TooManyRequestsException) {
            return false;
        }

        return $this->notifications->filesUploaded($user, $folder, $count);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function session(array $user, string $sessionUuid): array
    {
        $session = Uuid::isValid($sessionUuid) ? $this->sessions->findByUuid($sessionUuid) : null;
        if ($session === null) {
            throw new NotFoundException('La sesión de subida no existe o expiró. Vuelve a subir el archivo.', 'upload_session_not_found');
        }
        if ((int) $session['user_id'] !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
            throw new ForbiddenException('Esta subida pertenece a otra persona.');
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function activeSession(array $user, string $sessionUuid): array
    {
        $session = $this->session($user, $sessionUuid);
        if ($session['status'] !== 'active' || $session['file_status'] !== 'uploading') {
            throw new ConflictException('Esta subida ya terminó o fue cancelada.', 'upload_session_closed');
        }
        if (strtotime($session['expires_at'] . ' UTC') < time()) {
            throw new ConflictException('Esta subida expiró (más de 24 horas). Vuelve a subir el archivo.', 'upload_session_expired');
        }
        // Verificar que el usuario siga teniendo permiso de edición.
        $folder = $this->folders->find((int) $session['folder_id']);
        if ($folder === null) {
            throw new NotFoundException('La carpeta de destino ya no existe.');
        }
        $this->folderService->getWithAccess($user, (string) $folder['uuid'], 'editor');

        return $session;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function uploadingFile(array $user, string $fileUuid): array
    {
        $file = Uuid::isValid($fileUuid) ? $this->files->findByUuid($fileUuid) : null;
        if ($file === null || $file['status'] !== 'uploading') {
            throw new NotFoundException('La subida no existe o ya terminó.', 'upload_not_found');
        }
        if ((int) $file['uploaded_by'] !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
            throw new ForbiddenException('Esta subida pertenece a otra persona.');
        }
        $this->folderService->getWithAccess($user, (string) $file['folder_uuid'], 'editor');

        return $file;
    }
}
