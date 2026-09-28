<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\EntityRepository;
use App\Repositories\FolderMemberRepository;
use App\Repositories\FolderRepository;
use App\Repositories\InvitationRepository;
use App\Repositories\Repository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\RequestContext;
use App\Support\Token;
use App\Support\Uuid;
use App\Support\Validator;

/**
 * Invitaciones por correo: registro solo por invitación.
 */
final class InvitationService
{
    private const TTL = '+14 days';

    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly EntityRepository $entities,
        private readonly FolderMemberRepository $members,
        private readonly FolderRepository $folders,
        private readonly Token $token,
        private readonly Config $config,
        private readonly MailService $mail,
        private readonly AuditService $audit,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * Crea (o renueva) una invitación y envía el correo.
     *
     * @param array<string, mixed> $inviter
     * @param array<string, mixed>|null $folder carpeta raíz a la que se invita
     *
     * @return array{url: string, mailed: bool}
     */
    public function invite(string $email, array $inviter, ?array $folder, ?string $permission, ?int $entityId, RequestContext $ctx): array
    {
        $email = Validator::normalizeEmail($email);
        if ($entityId === null) {
            $domain = substr((string) strrchr($email, '@'), 1);
            $entity = $domain !== '' ? $this->entities->findByDomain($domain) : null;
            $entityId = $entity !== null ? (int) $entity['id'] : null;
        }
        $plain = Token::random();
        $hash = $this->token->hash($plain);
        $expires = Repository::now(self::TTL);
        $folderId = $folder !== null ? (int) $folder['id'] : null;
        $existing = $this->invitations->findPending($email, $folderId);
        if ($existing !== null) {
            $this->invitations->refresh((int) $existing['id'], $hash, $expires, $permission);
            $invitationId = (int) $existing['id'];
        } else {
            $invitationId = $this->invitations->create([
                'uuid' => Uuid::v4(),
                'email' => $email,
                'entity_id' => $entityId,
                'invited_by' => (int) $inviter['id'],
                'folder_id' => $folderId,
                'permission' => $folder !== null ? $permission : null,
                'token_hash' => $hash,
                'expires_at' => $expires,
                'created_at' => Repository::now(),
            ]);
        }
        $url = $this->config->url('/invitation/' . $plain);
        $mailed = $this->mail->send($email, sprintf('%s te invitó a %s', $inviter['name'], $folder !== null ? '"' . $folder['name'] . '"' : $this->config->string('app.name')), 'invitation', [
            'inviterName' => $inviter['name'],
            'inviterEntity' => $inviter['entity_name'] ?? null,
            'folderName' => $folder['name'] ?? null,
            'permissionLabel' => $permission !== null ? (AccessService::LABELS[$permission] ?? $permission) : null,
            'url' => $url,
            'days' => 14,
        ]);
        $this->audit->log('invite', (int) $inviter['id'], 'invitation', $invitationId, ['email' => $email, 'folder' => $folder['name'] ?? null, 'permission' => $permission], $ctx);

        return ['url' => $url, 'mailed' => $mailed];
    }

    /**
     * Invitación válida para mostrar la pantalla de aceptación.
     *
     * @return array<string, mixed>
     */
    public function findValid(string $plainToken): array
    {
        $invitation = $this->invitations->findValidByTokenHash($this->token->hash($plainToken));
        if ($invitation === null) {
            throw new NotFoundException('Esta invitación expiró o ya fue utilizada. Pide a quien te invitó que te envíe una nueva.', 'invitation_invalid');
        }

        return $invitation;
    }

    /**
     * Si el correo ya tiene cuenta, aplica la invitación directamente.
     *
     * @param array<string, mixed> $invitation
     *
     * @return array<string, mixed>|null usuario existente
     */
    public function applyToExistingUser(array $invitation): ?array
    {
        $user = $this->users->findByEmail((string) $invitation['email']);
        if ($user === null) {
            return null;
        }
        $this->applyPendingInvitations($user);

        return $user;
    }

    /**
     * Crea la cuenta del invitado, aplica todas sus invitaciones pendientes e inicia sesión.
     *
     * @param array<string, mixed> $invitation
     * @param array<string, mixed> $input
     *
     * @return array{user: array<string, mixed>, folderUuid: string|null}
     */
    public function accept(array $invitation, array $input, RequestContext $ctx): array
    {
        $v = Validator::make($input)
            ->required('name', 'El nombre')
            ->maxLength('name', 150, 'El nombre')
            ->maxLength('entity', 190, 'La entidad')
            ->password('password', 'password_confirmation');
        $v->validate();
        $email = (string) $invitation['email'];
        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException('Ya existe una cuenta con este correo. Inicia sesión.', 'email_taken');
        }
        $entityId = $invitation['entity_id'] !== null ? (int) $invitation['entity_id'] : null;
        $entityName = trim($v->string('entity'));
        if ($entityId === null && $entityName !== '') {
            $entity = $this->entities->findByName($entityName);
            $entityId = $entity !== null ? (int) $entity['id'] : $this->entities->create([
                'uuid' => Uuid::v4(),
                'name' => mb_substr($entityName, 0, 190),
                'domain' => null,
                'created_at' => Repository::now(),
                'updated_at' => Repository::now(),
            ]);
        }
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $userId = $this->users->transaction(function () use ($email, $v, $entityId, $password): int {
            return $this->users->create([
                'uuid' => Uuid::v4(),
                'entity_id' => $entityId,
                'name' => $v->string('name'),
                'email' => $email,
                'password_hash' => AuthService::hashPassword($password),
                'role' => 'user',
                'email_verified_at' => Repository::now(),
                'status' => 'active',
                'created_at' => Repository::now(),
                'updated_at' => Repository::now(),
            ]);
        });
        $user = (array) $this->users->find($userId);
        $this->applyPendingInvitations($user);
        $this->audit->log('invitation_accepted', $userId, 'invitation', (int) $invitation['id'], ['email' => $email], $ctx);
        $this->auth->startSession($user, false);

        return ['user' => $user, 'folderUuid' => $invitation['folder_uuid'] ?? null];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function applyPendingInvitations(array $user): void
    {
        foreach ($this->invitations->pendingForEmail((string) $user['email']) as $pending) {
            if ($pending['folder_id'] !== null && $this->folders->find((int) $pending['folder_id']) !== null) {
                $current = $this->members->permission((int) $pending['folder_id'], (int) $user['id']);
                $wanted = (string) ($pending['permission'] ?? 'viewer');
                if ($current === null || AccessService::RANK[$wanted] > AccessService::RANK[$current]) {
                    $this->members->add((int) $pending['folder_id'], (int) $user['id'], $wanted, (int) $pending['invited_by']);
                }
            }
            $this->invitations->markAccepted((int) $pending['id']);
        }
    }
}
