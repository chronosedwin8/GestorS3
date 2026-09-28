<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\FileRepository;
use App\Repositories\FolderRepository;
use App\Repositories\Repository;
use App\Repositories\ShareLinkRepository;
use App\Support\Config;
use App\Support\Present;
use App\Support\RequestContext;
use App\Support\Session;
use App\Support\Token;
use App\Support\Uuid;

/**
 * Enlaces públicos de solo lectura (sin cuenta), con contraseña, expiración y límite de descargas opcionales.
 * El token se deriva del uuid con HMAC(APP_KEY): en BD solo se guarda su hash, pero el dueño puede volver a copiar el enlace.
 */
final class ShareLinkService
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly FolderService $folderService,
        private readonly FolderRepository $folders,
        private readonly FileRepository $files,
        private readonly Token $token,
        private readonly Session $session,
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditService $audit,
    ) {
    }

    private function tokenFor(string $uuid): string
    {
        return $this->token->derive('share', $uuid);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $expired = $row['expires_at'] !== null && strtotime($row['expires_at'] . ' UTC') < time();
        $exhausted = $row['max_downloads'] !== null && (int) $row['downloads'] >= (int) $row['max_downloads'];

        return [
            'uuid' => $row['uuid'],
            'url' => $this->config->url('/s/' . $this->tokenFor((string) $row['uuid'])),
            'hasPassword' => $row['password_hash'] !== null,
            'expiresAt' => Present::iso($row['expires_at']),
            'maxDownloads' => $row['max_downloads'] !== null ? (int) $row['max_downloads'] : null,
            'downloads' => (int) $row['downloads'],
            'createdBy' => $row['creator_name'] ?? null,
            'createdAt' => Present::iso($row['created_at']),
            'revoked' => $row['revoked_at'] !== null,
            'active' => $row['revoked_at'] === null && !$expired && !$exhausted,
            'status' => $row['revoked_at'] !== null ? 'revoked' : ($expired ? 'expired' : ($exhausted ? 'exhausted' : 'active')),
        ];
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $user, string $folderUuid): array
    {
        [$folder] = $this->folderService->getWithAccess($user, $folderUuid, 'editor');

        return array_map(fn (array $r): array => $this->present($r), $this->links->listForFolder((int) $folder['id']));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input {password?, expiresInDays?, maxDownloads?}
     *
     * @return array<string, mixed>
     */
    public function create(array $user, string $folderUuid, array $input, RequestContext $ctx): array
    {
        [$folder] = $this->folderService->getWithAccess($user, $folderUuid, 'editor');
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if ($password !== '' && mb_strlen($password) < 4) {
            throw new ValidationException('La contraseña del enlace debe tener al menos 4 caracteres.');
        }
        $days = (int) ($input['expiresInDays'] ?? 0);
        if ($days < 0 || $days > 365) {
            throw new ValidationException('La expiración debe estar entre 1 y 365 días.');
        }
        $max = (int) ($input['maxDownloads'] ?? 0);
        if ($max < 0 || $max > 100000) {
            throw new ValidationException('El límite de descargas no es válido.');
        }
        $uuid = Uuid::v4();
        $id = $this->links->create([
            'uuid' => $uuid,
            'folder_id' => (int) $folder['id'],
            'created_by' => (int) $user['id'],
            'token_hash' => $this->token->hash($this->tokenFor($uuid)),
            'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
            'expires_at' => $days > 0 ? Repository::now('+' . $days . ' days') : null,
            'max_downloads' => $max > 0 ? $max : null,
            'downloads' => 0,
            'created_at' => Repository::now(),
        ]);
        $this->audit->log('share_link_create', (int) $user['id'], 'folder', (int) $folder['id'], ['link' => $uuid, 'password' => $password !== '', 'days' => $days, 'max' => $max], $ctx);
        $row = $this->links->findByUuid($uuid);

        return $this->present((array) $row + ['creator_name' => $user['name'], 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function revoke(array $user, string $linkUuid, RequestContext $ctx): void
    {
        $link = Uuid::isValid($linkUuid) ? $this->links->findByUuid($linkUuid) : null;
        if ($link === null) {
            throw new NotFoundException('El enlace no existe.');
        }
        $folder = $this->folders->find((int) $link['folder_id']);
        if ($folder === null) {
            throw new NotFoundException('El enlace no existe.');
        }
        $this->folderService->getWithAccess($user, (string) $folder['uuid'], 'editor');
        $this->links->revoke((int) $link['id']);
        $this->audit->log('share_link_revoke', (int) $user['id'], 'folder', (int) $folder['id'], ['link' => $link['uuid']], $ctx, (int) $link['id']);
    }

    // ------------------------------------------------------------------ Acceso público

    /**
     * Resuelve un token público. Lanza 404/403 con mensajes claros si no es utilizable.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $token): array
    {
        $link = strlen($token) >= 20 && strlen($token) <= 64 ? $this->links->findByTokenHash($this->token->hash($token)) : null;
        if ($link === null || $link['folder_deleted_at'] !== null) {
            throw new NotFoundException('Este enlace no existe o la carpeta ya no está disponible.', 'share_not_found');
        }
        if ($link['revoked_at'] !== null) {
            throw new ForbiddenException('Este enlace fue desactivado por quien lo compartió.', 'share_revoked');
        }
        if ($link['expires_at'] !== null && strtotime($link['expires_at'] . ' UTC') < time()) {
            throw new ForbiddenException('Este enlace expiró. Pide a quien lo compartió uno nuevo.', 'share_expired');
        }

        return $link;
    }

    /**
     * @param array<string, mixed> $link
     */
    public function isUnlocked(array $link): bool
    {
        if ($link['password_hash'] === null) {
            return true;
        }
        $unlocked = $this->session->get('share_unlocked', []);

        return is_array($unlocked) && in_array((int) $link['id'], $unlocked, true);
    }

    /**
     * @param array<string, mixed> $link
     */
    public function unlock(array $link, string $password, RequestContext $ctx): void
    {
        $this->rateLimiter->hit('share-unlock:' . $ctx->ip . ':' . $link['id'], 10, 900);
        if ($link['password_hash'] === null || !password_verify($password, (string) $link['password_hash'])) {
            throw new ValidationException('La contraseña no es correcta.', 'invalid_password');
        }
        $unlocked = $this->session->get('share_unlocked', []);
        $unlocked = is_array($unlocked) ? $unlocked : [];
        $unlocked[] = (int) $link['id'];
        $this->session->set('share_unlocked', array_values(array_unique($unlocked)));
    }

    /**
     * @param array<string, mixed> $link
     */
    public function requireUnlocked(array $link): void
    {
        if (!$this->isUnlocked($link)) {
            throw new ForbiddenException('Este enlace está protegido con contraseña.', 'share_locked');
        }
    }

    /**
     * Carpeta dentro del enlace (la compartida o una de sus subcarpetas).
     *
     * @param array<string, mixed> $link
     *
     * @return array<string, mixed>
     */
    public function folderInLink(array $link, ?string $folderUuid): array
    {
        if ($folderUuid === null || $folderUuid === '' || $folderUuid === $link['folder_uuid']) {
            return (array) $this->folders->find((int) $link['folder_id']);
        }
        $folder = Uuid::isValid($folderUuid) ? $this->folders->findByUuid($folderUuid) : null;
        if ($folder === null || !$this->folders->isWithin((int) $folder['id'], (int) $link['folder_id'])) {
            throw new NotFoundException('No encontramos esta carpeta dentro del enlace.');
        }

        return $folder;
    }

    /**
     * @param array<string, mixed> $link
     *
     * @return array<string, mixed>
     */
    public function fileInLink(array $link, string $fileUuid): array
    {
        $file = Uuid::isValid($fileUuid) ? $this->files->findByUuid($fileUuid) : null;
        if ($file === null || $file['status'] !== 'ready' || !$this->folders->isWithin((int) $file['folder_id'], (int) $link['folder_id'])) {
            throw new NotFoundException('No encontramos este archivo dentro del enlace.');
        }

        return $file;
    }

    /**
     * Cuenta una descarga del enlace y la registra en auditoría.
     *
     * @param array<string, mixed> $link
     * @param array<string, mixed> $meta
     */
    public function registerDownload(array $link, string $action, string $targetType, int $targetId, array $meta, RequestContext $ctx): void
    {
        if (!$this->links->incrementDownloads((int) $link['id'])) {
            throw new ForbiddenException('Este enlace alcanzó su límite de descargas. Pide a quien lo compartió uno nuevo.', 'share_exhausted');
        }
        $this->audit->log($action, null, $targetType, $targetId, $meta, $ctx, (int) $link['id']);
    }
}
