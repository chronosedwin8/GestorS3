<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AuditLogRepository;
use App\Repositories\EntityRepository;
use App\Repositories\FolderRepository;
use App\Repositories\InvitationRepository;
use App\Repositories\Repository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\RequestContext;
use App\Support\Uuid;
use App\Support\Validator;

/**
 * Operaciones del panel de administración.
 */
final class AdminService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityRepository $entities,
        private readonly InvitationRepository $invitations,
        private readonly FolderRepository $folders,
        private readonly AuditLogRepository $auditLog,
        private readonly InvitationService $invitationService,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly AuthService $auth,
        private readonly MailService $mail,
        private readonly Config $config,
    ) {
    }

    public const ROLE_LABELS = [
        'admin' => 'Administrador',
        'user' => 'Usuario de la institución',
        'external' => 'Externo',
    ];

    /**
     * Crea una cuenta directamente. Si no se indica contraseña se genera una temporal; opcionalmente
     * envía un correo de bienvenida con un enlace para que la persona defina su propia contraseña.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     *
     * @return array{user: array<string, mixed>, password: string|null, link: string|null, mailed: bool}
     */
    public function createUser(array $admin, array $input, RequestContext $ctx): array
    {
        $v = Validator::make($input)
            ->required('name', 'El nombre')->maxLength('name', 150, 'El nombre')
            ->required('email', 'El correo')->email('email')
            ->in('role', array_keys(self::ROLE_LABELS), 'El tipo de cuenta');
        $email = Validator::normalizeEmail($v->string('email'));
        if ($email !== '' && $this->users->findByEmail($email) !== null) {
            $v->addError('email', 'Ya existe una cuenta con ese correo.');
        }
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if ($password !== '') {
            $v->password('password');
        }
        $v->validate();

        $entityId = $this->entityIdFrom($v->string('entity'));
        $generated = null;
        if ($password === '') {
            $generated = self::generatePassword();
            $password = $generated;
        }
        $role = $v->string('role');
        $user = $this->auth->createUser($email, $v->string('name'), $password, $role, $entityId);
        // Si ya la habían invitado a carpetas, aplicar esos accesos.
        $this->invitationService->applyPendingInvitations($user);

        $link = null;
        $mailed = false;
        if (!empty($input['send_welcome'])) {
            $link = $this->auth->accessLink($user, 7);
            $mailed = $this->mail->send($email, sprintf('%s creó tu cuenta en %s', $admin['name'], $this->settingsAppName()), 'welcome', [
                'name' => $user['name'],
                'adminName' => $admin['name'],
                'roleLabel' => self::ROLE_LABELS[$role],
                'canCreate' => $role !== 'external',
                'url' => $link,
                'days' => 7,
            ]);
        }
        $this->audit->log('user_create', (int) $admin['id'], 'user', (int) $user['id'], ['email' => $email, 'role' => $role], $ctx);

        return ['user' => $user, 'password' => $generated, 'link' => $link, 'mailed' => $mailed];
    }

    /**
     * Genera un enlace para que el usuario defina una nueva contraseña y se lo envía por correo.
     *
     * @param array<string, mixed> $admin
     *
     * @return array{user: array<string, mixed>, link: string, mailed: bool}
     */
    public function sendAccessLink(array $admin, string $uuid, RequestContext $ctx): array
    {
        $user = $this->users->findByUuid($uuid);
        if ($user === null) {
            throw new NotFoundException('El usuario no existe.');
        }
        if ($user['status'] !== 'active') {
            throw new ValidationException('La cuenta está deshabilitada: actívala antes de enviar el acceso.');
        }
        $link = $this->auth->accessLink($user, 7);
        $mailed = $this->mail->send((string) $user['email'], 'Tu enlace para entrar a ' . $this->settingsAppName(), 'welcome', [
            'name' => $user['name'],
            'adminName' => $admin['name'],
            'roleLabel' => self::ROLE_LABELS[$user['role']] ?? $user['role'],
            'canCreate' => $user['role'] !== 'external',
            'url' => $link,
            'days' => 7,
        ]);
        $this->audit->log('access_link_sent', (int) $admin['id'], 'user', (int) $user['id'], ['email' => $user['email']], $ctx);

        return ['user' => $user, 'link' => $link, 'mailed' => $mailed];
    }

    public static function generatePassword(): string
    {
        // Sin caracteres ambiguos (0/O, 1/l/I) para que sea fácil de dictar o copiar.
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if ($i === 3 || $i === 7) {
                $out .= '-';
            }
        }

        return $out;
    }

    private function entityIdFrom(string $entityUuid): ?int
    {
        if ($entityUuid === '') {
            return null;
        }
        $entity = $this->entities->findByUuid($entityUuid);
        if ($entity === null) {
            throw new ValidationException('La entidad seleccionada no existe.');
        }

        return (int) $entity['id'];
    }

    private function settingsAppName(): string
    {
        return $this->config->string('app.name', 'Compartir Archivos');
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function users(string $query, ?string $entityUuid, int $page, int $perPage = 25, ?string $role = null): array
    {
        $entityId = null;
        if ($entityUuid !== null && $entityUuid !== '') {
            $entity = $this->entities->findByUuid($entityUuid);
            $entityId = $entity !== null ? (int) $entity['id'] : -1;
        }

        $role = in_array($role, array_keys(self::ROLE_LABELS), true) ? $role : null;

        return $this->users->search($query, $entityId, $perPage, max(0, $page - 1) * $perPage, $role);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingInvitations(): array
    {
        return $this->invitations->pendingAll();
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     */
    public function updateUser(array $admin, string $uuid, array $input, RequestContext $ctx): void
    {
        $user = $this->users->findByUuid($uuid);
        if ($user === null) {
            throw new NotFoundException('El usuario no existe.');
        }
        $v = Validator::make($input)
            ->required('name', 'El nombre')->maxLength('name', 150, 'El nombre')
            ->in('role', array_keys(self::ROLE_LABELS), 'El tipo de cuenta')
            ->in('status', ['active', 'disabled'], 'El estado');
        $v->validate();
        $role = $v->string('role');
        $status = $v->string('status');
        if ((int) $user['id'] === (int) $admin['id'] && ($role !== 'admin' || $status !== 'active')) {
            throw new ForbiddenException('No puedes quitarte el rol de administrador ni deshabilitar tu propia cuenta.');
        }
        $entityId = null;
        $entityUuid = $v->string('entity');
        if ($entityUuid !== '') {
            $entity = $this->entities->findByUuid($entityUuid);
            if ($entity === null) {
                throw new ValidationException('La entidad seleccionada no existe.');
            }
            $entityId = (int) $entity['id'];
        }
        $this->users->update((int) $user['id'], [
            'name' => $v->string('name'),
            'role' => $role,
            'status' => $status,
            'entity_id' => $entityId,
        ]);
        $this->audit->log('user_update', (int) $admin['id'], 'user', (int) $user['id'], ['role' => $role, 'status' => $status], $ctx);
    }

    /**
     * Invitación general (sin carpeta) desde el panel.
     *
     * @param array<string, mixed> $admin
     *
     * @return list<array<string, mixed>>
     */
    public function inviteUsers(array $admin, string $emailsRaw, ?string $entityUuid, RequestContext $ctx, string $role = 'user'): array
    {
        $emails = Validator::splitEmails($emailsRaw);
        if ($emails === []) {
            throw new ValidationException('Escribe al menos un correo electrónico.');
        }
        $entityId = null;
        if ($entityUuid !== null && $entityUuid !== '') {
            $entity = $this->entities->findByUuid($entityUuid);
            $entityId = $entity !== null ? (int) $entity['id'] : null;
        }
        $results = [];
        foreach (array_slice($emails, 0, 50) as $email) {
            if (!Validator::isEmail($email)) {
                $results[] = ['email' => $email, 'status' => 'error', 'message' => 'Correo no válido.'];
                continue;
            }
            if ($this->users->findByEmail($email) !== null) {
                $results[] = ['email' => $email, 'status' => 'already_member', 'message' => 'Ya tiene cuenta.'];
                continue;
            }
            $invite = $this->invitationService->invite($email, $admin, null, null, $entityId, $ctx, $role === 'external' ? 'external' : 'user');
            $results[] = [
                'email' => $email,
                'status' => 'invited',
                'message' => $invite['mailed'] ? 'Invitación enviada.' : 'Invitación creada (correo no enviado).',
                'link' => $invite['mailed'] ? null : $invite['url'],
            ];
        }

        return $results;
    }

    // ------------------------------------------------------------------ Entidades

    /**
     * @return list<array<string, mixed>>
     */
    public function entities(): array
    {
        return $this->entities->allWithCounts();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entityOptions(): array
    {
        return $this->entities->list();
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input
     */
    public function saveEntity(array $admin, ?string $uuid, array $input, RequestContext $ctx): void
    {
        $v = Validator::make($input)->required('name', 'El nombre')->maxLength('name', 190, 'El nombre')->maxLength('domain', 190, 'El dominio');
        $v->validate();
        $name = $v->string('name');
        $domain = mb_strtolower(ltrim($v->string('domain'), '@'));
        if ($domain !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            throw new ValidationException('El dominio no es válido (ejemplo: entidad.gov.co).');
        }
        $existing = $this->entities->findByName($name);
        if ($uuid === null) {
            if ($existing !== null) {
                throw new ConflictException('Ya existe una entidad con ese nombre.');
            }
            $id = $this->entities->create([
                'uuid' => Uuid::v4(),
                'name' => $name,
                'domain' => $domain !== '' ? $domain : null,
                'created_at' => Repository::now(),
                'updated_at' => Repository::now(),
            ]);
            $this->audit->log('entity_create', (int) $admin['id'], 'entity', $id, ['name' => $name], $ctx);

            return;
        }
        $entity = $this->entities->findByUuid($uuid);
        if ($entity === null) {
            throw new NotFoundException('La entidad no existe.');
        }
        if ($existing !== null && (int) $existing['id'] !== (int) $entity['id']) {
            throw new ConflictException('Ya existe una entidad con ese nombre.');
        }
        $this->entities->update((int) $entity['id'], ['name' => $name, 'domain' => $domain !== '' ? $domain : null]);
        $this->audit->log('entity_update', (int) $admin['id'], 'entity', (int) $entity['id'], ['name' => $name], $ctx);
    }

    /**
     * @param array<string, mixed> $admin
     */
    public function deleteEntity(array $admin, string $uuid, RequestContext $ctx): void
    {
        $entity = $this->entities->findByUuid($uuid);
        if ($entity === null) {
            throw new NotFoundException('La entidad no existe.');
        }
        $this->entities->delete((int) $entity['id']);
        $this->audit->log('entity_delete', (int) $admin['id'], 'entity', (int) $entity['id'], ['name' => $entity['name']], $ctx);
    }

    // ------------------------------------------------------------------ Ajustes

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $input valores en GB para tamaños
     */
    public function saveSettings(array $admin, array $input, RequestContext $ctx): void
    {
        $gb = static function (mixed $value, string $label): ?string {
            $value = str_replace(',', '.', trim((string) $value));
            if ($value === '') {
                return null;
            }
            if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 5000) {
                throw new ValidationException(sprintf('%s debe ser un número mayor que 0.', $label));
            }

            return (string) (int) round((float) $value * 1073741824);
        };
        $int = static function (mixed $value, string $label): ?string {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            if (!ctype_digit($value) || (int) $value < 1) {
                throw new ValidationException(sprintf('%s debe ser un número entero mayor que 0.', $label));
            }

            return $value;
        };
        $values = [
            'max_file_bytes' => $gb($input['max_file_gb'] ?? '', 'El tamaño máximo por archivo'),
            'folder_max_bytes' => $gb($input['folder_max_gb'] ?? '', 'El tamaño máximo por carpeta'),
            'zip_max_bytes' => $gb($input['zip_max_gb'] ?? '', 'El tamaño máximo del ZIP'),
            'zip_max_files' => $int($input['zip_max_files'] ?? '', 'El número máximo de archivos por ZIP'),
            'blocked_extensions' => implode(',', SettingsService::parseExtensions((string) ($input['blocked_extensions'] ?? ''))),
            'internal_domains' => implode(',', SettingsService::parseDomains((string) ($input['internal_domains'] ?? ''))),
        ];
        if ($values['max_file_bytes'] !== null && (int) $values['max_file_bytes'] > 5 * 1024 * 1073741824) {
            throw new ValidationException('S3 admite como máximo 5 TB por archivo.');
        }
        foreach ($values as $key => $value) {
            $this->settings->save($key, $value);
        }
        $this->audit->log('settings_update', (int) $admin['id'], 'settings', null, $values, $ctx);
    }

    // ------------------------------------------------------------------ Actividad y almacenamiento

    /**
     * @param array{action?: string, user?: string, from?: string, to?: string} $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function activity(array $filters, int $page, int $perPage = 50): array
    {
        return $this->auditLog->search($filters, $perPage, max(0, $page - 1) * $perPage);
    }

    /**
     * @return list<string>
     */
    public function activityActions(): array
    {
        return $this->auditLog->distinctActions();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function storage(): array
    {
        return $this->folders->storageUsage();
    }
}
