<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\FolderMemberRepository;

/**
 * Permisos por carpeta. Se asignan en la carpeta raíz y se heredan a todas las subcarpetas.
 * Los administradores tienen acceso de propietario a todas las carpetas.
 */
final class AccessService
{
    public const RANK = ['viewer' => 1, 'editor' => 2, 'owner' => 3];

    public const LABELS = ['owner' => 'Propietario', 'editor' => 'Editor', 'viewer' => 'Lector'];

    public function __construct(private readonly FolderMemberRepository $members)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $folder
     */
    public function permission(array $user, array $folder): ?string
    {
        if (($user['role'] ?? '') === 'admin') {
            return 'owner';
        }

        return $this->members->permission((int) $folder['root_id'], (int) $user['id']);
    }

    /**
     * Exige un permiso mínimo. Sin acceso alguno responde 404 para no revelar la existencia de la carpeta.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $folder
     */
    public function require(array $user, array $folder, string $minimum): string
    {
        $permission = $this->permission($user, $folder);
        if ($permission === null) {
            throw new NotFoundException('No encontramos esta carpeta o no tienes acceso a ella.');
        }
        if (!self::allows($permission, $minimum)) {
            throw new ForbiddenException(match ($minimum) {
                'owner' => 'Solo el propietario de la carpeta puede hacer esto.',
                'editor' => 'Tienes acceso de solo lectura a esta carpeta. Pide al propietario permiso de edición.',
                default => 'No tienes permiso para realizar esta acción.',
            });
        }

        return $permission;
    }

    public static function allows(?string $permission, string $minimum): bool
    {
        if ($permission === null || !isset(self::RANK[$permission], self::RANK[$minimum])) {
            return false;
        }

        return self::RANK[$permission] >= self::RANK[$minimum];
    }

    /**
     * Acciones disponibles en la interfaz para un permiso dado.
     *
     * @return array<string, bool>
     */
    public static function capabilities(?string $permission, bool $isRoot): array
    {
        $editor = self::allows($permission, 'editor');
        $owner = self::allows($permission, 'owner');

        return [
            'view' => $permission !== null,
            'upload' => $editor,
            'createFolder' => $editor,
            'rename' => $isRoot ? $owner : $editor,
            'deleteItems' => $editor,
            'deleteFolder' => $isRoot ? $owner : $editor,
            'manageMembers' => $owner,
            'invite' => $editor,
            'shareLinks' => $editor,
            'seeDownloads' => $editor,
        ];
    }

    /**
     * Permisos que el actor puede otorgar al invitar.
     *
     * @return list<string>
     */
    public static function grantable(?string $actorPermission): array
    {
        return match ($actorPermission) {
            'owner' => ['editor', 'viewer'],
            'editor' => ['viewer'],
            default => [],
        };
    }
}
