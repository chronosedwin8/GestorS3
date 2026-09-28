<?php

declare(strict_types=1);

namespace App\Repositories;

final class InvitationRepository extends Repository
{
    private const SELECT = 'SELECT i.*, u.name AS inviter_name, u.email AS inviter_email,
            f.uuid AS folder_uuid, f.name AS folder_name, e.name AS entity_name
        FROM invitations i
        JOIN users u ON u.id = i.invited_by
        LEFT JOIN folders f ON f.id = i.folder_id AND f.deleted_at IS NULL
        LEFT JOIN entities e ON e.id = i.entity_id';

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insert('invitations', $data);
    }

    /** @return array<string, mixed>|null */
    public function findValidByTokenHash(string $hash): ?array
    {
        return $this->one(self::SELECT . ' WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.expires_at > ?', [$hash, self::now()]);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one(self::SELECT . ' WHERE i.uuid = ?', [$uuid]);
    }

    /** @return array<string, mixed>|null */
    public function findPending(string $email, ?int $folderId): ?array
    {
        if ($folderId === null) {
            return $this->one(self::SELECT . ' WHERE i.email = ? AND i.folder_id IS NULL AND i.accepted_at IS NULL AND i.expires_at > ? ORDER BY i.id DESC LIMIT 1', [$email, self::now()]);
        }

        return $this->one(self::SELECT . ' WHERE i.email = ? AND i.folder_id = ? AND i.accepted_at IS NULL AND i.expires_at > ? ORDER BY i.id DESC LIMIT 1', [$email, $folderId, self::now()]);
    }

    /**
     * Todas las invitaciones pendientes de un correo (para aplicarlas al crear la cuenta).
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForEmail(string $email): array
    {
        return $this->all(self::SELECT . ' WHERE i.email = ? AND i.accepted_at IS NULL AND i.expires_at > ? ORDER BY i.id', [$email, self::now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForFolder(int $folderId): array
    {
        return $this->all(self::SELECT . ' WHERE i.folder_id = ? AND i.accepted_at IS NULL AND i.expires_at > ? ORDER BY i.created_at DESC', [$folderId, self::now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingAll(int $limit = 100): array
    {
        return $this->all(self::SELECT . " WHERE i.accepted_at IS NULL AND i.expires_at > ? ORDER BY i.created_at DESC LIMIT $limit", [self::now()]);
    }

    public function refresh(int $id, string $tokenHash, string $expiresAt, ?string $permission, ?string $role = null): void
    {
        $this->exec('UPDATE invitations SET token_hash = ?, expires_at = ?, permission = COALESCE(?, permission), role = COALESCE(?, role) WHERE id = ?', [$tokenHash, $expiresAt, $permission, $role, $id]);
    }

    public function markAccepted(int $id): void
    {
        $this->exec('UPDATE invitations SET accepted_at = ? WHERE id = ? AND accepted_at IS NULL', [self::now(), $id]);
    }

    public function delete(int $id): void
    {
        $this->exec('DELETE FROM invitations WHERE id = ?', [$id]);
    }
}
