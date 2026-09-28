<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\FolderMemberRepository;
use App\Repositories\FolderRepository;
use App\Repositories\InvitationRepository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\Present;
use App\Support\RequestContext;
use App\Support\Validator;

/**
 * Miembros de una carpeta raíz: listar, invitar, cambiar permiso y quitar.
 */
final class MemberService
{
    public function __construct(
        private readonly FolderService $folderService,
        private readonly FolderRepository $folders,
        private readonly FolderMemberRepository $members,
        private readonly UserRepository $users,
        private readonly InvitationRepository $invitations,
        private readonly InvitationService $invitationService,
        private readonly MailService $mail,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $folder
     *
     * @return array<string, mixed>
     */
    private function rootOf(array $folder): array
    {
        return $folder['parent_id'] === null ? $folder : (array) $this->folders->find((int) $folder['root_id']);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function list(array $user, string $folderUuid): array
    {
        [$folder, $permission] = $this->folderService->getWithAccess($user, $folderUuid, 'viewer');
        $root = $this->rootOf($folder);
        $canSeeInvites = AccessService::allows($permission, 'editor');

        return [
            'root' => ['uuid' => $root['uuid'], 'name' => $root['name']],
            'permission' => $permission,
            'grantable' => AccessService::grantable($permission),
            'canManage' => AccessService::allows($permission, 'owner'),
            'members' => array_map([Present::class, 'member'], $this->members->listForFolder((int) $root['id'])),
            'invitations' => $canSeeInvites ? array_map(static fn (array $i): array => [
                'uuid' => $i['uuid'],
                'email' => $i['email'],
                'permission' => $i['permission'],
                'invitedBy' => $i['inviter_name'],
                'expiresAt' => Present::iso($i['expires_at']),
                'createdAt' => Present::iso($i['created_at']),
            ], $this->invitations->pendingForFolder((int) $root['id'])) : [],
        ];
    }

    /**
     * Invita a uno o varios correos. Si el correo ya tiene cuenta, se agrega directamente.
     *
     * @param array<string, mixed> $user
     * @param list<string> $emails
     *
     * @return list<array<string, mixed>>
     */
    public function invite(array $user, string $folderUuid, array $emails, string $permission, RequestContext $ctx): array
    {
        [$folder, $actorPermission] = $this->folderService->getWithAccess($user, $folderUuid, 'editor');
        $root = $this->rootOf($folder);
        if (!in_array($permission, ['editor', 'viewer'], true)) {
            throw new ValidationException('Elige un permiso válido.', 'invalid_permission');
        }
        if (!in_array($permission, AccessService::grantable($actorPermission), true)) {
            throw new ForbiddenException('Como editor solo puedes invitar personas con permiso de lectura.');
        }
        if ($emails === []) {
            throw new ValidationException('Escribe al menos un correo electrónico.', 'validation_failed', ['fields' => ['emails' => 'Obligatorio']]);
        }
        if (count($emails) > 50) {
            throw new ValidationException('Puedes invitar hasta 50 correos a la vez.');
        }
        $this->rateLimiter->hit('invite:user:' . $user['id'], 200, 3600, 'Enviaste muchas invitaciones en poco tiempo. Espera un rato e inténtalo de nuevo.');
        $this->rateLimiter->hit('invite:ip:' . $ctx->ip, 300, 3600);

        $results = [];
        foreach ($emails as $email) {
            $email = Validator::normalizeEmail($email);
            if (!Validator::isEmail($email)) {
                $results[] = ['email' => $email, 'status' => 'error', 'message' => 'El correo no es válido.'];
                continue;
            }
            $existing = $this->users->findByEmail($email);
            if ($existing !== null) {
                $results[] = $this->addExisting($user, $root, $existing, $permission, $ctx);
                continue;
            }
            $invite = $this->invitationService->invite($email, $user, $root, $permission, null, $ctx);
            $result = [
                'email' => $email,
                'status' => 'invited',
                'message' => $invite['mailed'] ? 'Invitación enviada por correo.' : 'Invitación creada. No se pudo enviar el correo: comparte el enlace manualmente.',
            ];
            if (!$invite['mailed']) {
                $result['link'] = $invite['url'];
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $root
     * @param array<string, mixed> $target
     *
     * @return array<string, mixed>
     */
    private function addExisting(array $actor, array $root, array $target, string $permission, RequestContext $ctx): array
    {
        $email = (string) $target['email'];
        if ($target['status'] !== 'active') {
            return ['email' => $email, 'status' => 'error', 'message' => 'Esta cuenta está deshabilitada.'];
        }
        $current = $this->members->permission((int) $root['id'], (int) $target['id']);
        if ($current !== null) {
            return ['email' => $email, 'status' => 'already_member', 'message' => sprintf('Ya tiene acceso como %s.', mb_strtolower(AccessService::LABELS[$current]))];
        }
        $this->members->add((int) $root['id'], (int) $target['id'], $permission, (int) $actor['id']);
        $this->audit->log('share', (int) $actor['id'], 'folder', (int) $root['id'], ['email' => $email, 'permission' => $permission], $ctx);
        $mailed = $this->mail->send($email, sprintf('%s compartió "%s" contigo', $actor['name'], $root['name']), 'folder-shared', [
            'name' => $target['name'],
            'inviterName' => $actor['name'],
            'folderName' => $root['name'],
            'permissionLabel' => AccessService::LABELS[$permission],
            'url' => $this->config->url('/folders/' . $root['uuid']),
        ]);

        return [
            'email' => $email,
            'status' => 'added',
            'message' => $mailed ? 'Agregado. Le avisamos por correo.' : 'Agregado. Ya puede verla al iniciar sesión.',
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    public function changePermission(array $user, string $folderUuid, string $userUuid, string $permission, RequestContext $ctx): void
    {
        [$folder] = $this->folderService->getWithAccess($user, $folderUuid, 'owner');
        $root = $this->rootOf($folder);
        if (!in_array($permission, ['editor', 'viewer'], true)) {
            throw new ValidationException('Elige un permiso válido.', 'invalid_permission');
        }
        $target = $this->users->findByUuid($userUuid);
        $current = $target !== null ? $this->members->permission((int) $root['id'], (int) $target['id']) : null;
        if ($target === null || $current === null) {
            throw new NotFoundException('Esa persona no es miembro de la carpeta.');
        }
        if ($current === 'owner') {
            throw new ForbiddenException('No se puede cambiar el permiso del propietario.');
        }
        $this->members->updatePermission((int) $root['id'], (int) $target['id'], $permission);
        $this->audit->log('permission_change', (int) $user['id'], 'folder', (int) $root['id'], ['email' => $target['email'], 'from' => $current, 'to' => $permission], $ctx);
    }

    /**
     * Quita a un miembro. Un miembro también puede salir por sí mismo.
     *
     * @param array<string, mixed> $user
     */
    public function remove(array $user, string $folderUuid, string $userUuid, RequestContext $ctx): void
    {
        $target = $this->users->findByUuid($userUuid);
        if ($target === null) {
            throw new NotFoundException('Esa persona no es miembro de la carpeta.');
        }
        $self = (int) $target['id'] === (int) $user['id'];
        [$folder] = $this->folderService->getWithAccess($user, $folderUuid, $self ? 'viewer' : 'owner');
        $root = $this->rootOf($folder);
        $current = $this->members->permission((int) $root['id'], (int) $target['id']);
        if ($current === null) {
            throw new NotFoundException('Esa persona no es miembro de la carpeta.');
        }
        if ($current === 'owner') {
            throw new ForbiddenException('El propietario no puede salir de su propia carpeta. Puedes eliminarla si ya no la necesitas.');
        }
        $this->members->remove((int) $root['id'], (int) $target['id']);
        $this->audit->log($self ? 'leave' : 'revoke', (int) $user['id'], 'folder', (int) $root['id'], ['email' => $target['email']], $ctx);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function cancelInvitation(array $user, string $invitationUuid, RequestContext $ctx): void
    {
        $invitation = $this->invitations->findByUuid($invitationUuid);
        if ($invitation === null || $invitation['folder_uuid'] === null) {
            throw new NotFoundException('La invitación no existe.');
        }
        $this->folderService->getWithAccess($user, (string) $invitation['folder_uuid'], 'editor');
        $this->invitations->delete((int) $invitation['id']);
        $this->audit->log('invitation_cancel', (int) $user['id'], 'invitation', (int) $invitation['id'], ['email' => $invitation['email']], $ctx);
    }
}
