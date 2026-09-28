<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\Repository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\RequestContext;
use App\Support\Session;
use App\Support\Token;
use App\Support\Uuid;
use App\Support\Validator;

/**
 * Autenticación: login con contraseña, enlaces mágicos, restablecimiento y sesión.
 */
final class AuthService
{
    private const RESET_TTL = '+60 minutes';
    private const MAGIC_TTL = '+20 minutes';

    /** @var array<string, mixed>|null|false false = aún no cargado */
    private array|null|false $currentUser = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly Session $session,
        private readonly Token $token,
        private readonly Config $config,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditService $audit,
        private readonly MailService $mail,
    ) {
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    /**
     * @return array<string, mixed>
     */
    public function attempt(string $email, string $password, RequestContext $ctx): array
    {
        $email = Validator::normalizeEmail($email);
        $this->rateLimiter->hit('login:ip:' . $ctx->ip, 30, 900);
        $this->rateLimiter->hit('login:email:' . $email, 8, 900, 'Demasiados intentos para este correo. Espera unos minutos o usa "Olvidé mi contraseña".');

        $user = $email !== '' ? $this->users->findByEmail($email) : null;
        $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';
        if (!is_array($user) || $hash === '' || !password_verify($password, $hash)) {
            // Tiempo constante aproximado cuando el usuario no existe.
            if (!is_array($user)) {
                password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }
            $this->audit->log('login_failed', is_array($user) ? (int) $user['id'] : null, 'user', is_array($user) ? (int) $user['id'] : null, ['email' => $email], $ctx);
            throw new ValidationException('El correo o la contraseña no son correctos.', 'invalid_credentials');
        }
        if ($user['status'] !== 'active') {
            throw new ForbiddenException('Tu cuenta está deshabilitada. Contacta al administrador.', 'account_disabled');
        }
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->users->update((int) $user['id'], ['password_hash' => self::hashPassword($password)]);
        }
        $this->rateLimiter->clear('login:email:' . $email);
        $this->startSession($user, false);
        $this->audit->log('login', (int) $user['id'], 'user', (int) $user['id'], ['method' => 'password'], $ctx);

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function startSession(array $user, bool $limited): void
    {
        $this->session->regenerate();
        $this->session->remove('_csrf');
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('auth_at', time());
        $this->session->set('last_activity', time());
        $this->session->set('limited', $limited);
        $this->users->touchLogin((int) $user['id']);
        $this->currentUser = $user;
    }

    /**
     * Usuario autenticado de la sesión actual (o null). Aplica expiración por inactividad.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->currentUser !== false) {
            return $this->currentUser;
        }
        $this->currentUser = null;
        $userId = $this->session->get('user_id');
        if (!is_int($userId)) {
            return null;
        }
        $limited = (bool) $this->session->get('limited', false);
        $idleMinutes = $limited ? $this->config->int('session.magic_lifetime_min', 120) : $this->config->int('session.lifetime_min', 480);
        $last = (int) $this->session->get('last_activity', 0);
        if ($last > 0 && time() - $last > $idleMinutes * 60) {
            $this->session->destroy();

            return null;
        }
        $user = $this->users->find($userId);
        if ($user === null || $user['status'] !== 'active') {
            $this->session->destroy();

            return null;
        }
        $this->session->set('last_activity', time());
        $user['limited_session'] = $limited;

        return $this->currentUser = $user;
    }

    /**
     * Olvida el usuario cacheado (útil entre peticiones en pruebas).
     */
    public function reset(): void
    {
        $this->currentUser = false;
    }

    public function logout(?RequestContext $ctx = null): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->audit->log('logout', (int) $user['id'], 'user', (int) $user['id'], [], $ctx);
        }
        $this->session->destroy();
        $this->currentUser = null;
    }

    // ------------------------------------------------------------------ Restablecer contraseña

    public function requestPasswordReset(string $email, RequestContext $ctx): void
    {
        $email = Validator::normalizeEmail($email);
        $this->rateLimiter->hit('reset:ip:' . $ctx->ip, 10, 900);
        $this->rateLimiter->hit('reset:email:' . $email, 3, 900, 'Ya enviamos un correo hace poco. Revisa tu bandeja de entrada (y la carpeta de spam).');
        $user = Validator::isEmail($email) ? $this->users->findByEmail($email) : null;
        if ($user === null || $user['status'] !== 'active') {
            return; // No revelar si el correo existe.
        }
        $plain = Token::random();
        $this->tokens->invalidateForUser('password_resets', (int) $user['id']);
        $this->tokens->create('password_resets', (int) $user['id'], $this->token->hash($plain), Repository::now(self::RESET_TTL));
        $this->mail->send((string) $user['email'], 'Restablece tu contraseña', 'password-reset', [
            'name' => $user['name'],
            'url' => $this->config->url('/reset-password/' . $plain),
            'minutes' => 60,
        ]);
        $this->audit->log('password_reset_requested', (int) $user['id'], 'user', (int) $user['id'], [], $ctx);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findResetUser(string $plainToken): ?array
    {
        $row = $this->tokens->findValid('password_resets', $this->token->hash($plainToken));

        return $row === null ? null : $this->users->find((int) $row['user_id']);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(string $plainToken, string $password, string $confirmation, RequestContext $ctx): array
    {
        Validator::make(['password' => $password, 'password_confirmation' => $confirmation])
            ->password('password', 'password_confirmation')
            ->validate();
        $row = $this->tokens->findValid('password_resets', $this->token->hash($plainToken));
        if ($row === null || !$this->tokens->markUsed('password_resets', (int) $row['id'])) {
            throw new NotFoundException('El enlace para restablecer la contraseña expiró o ya se usó. Solicita uno nuevo.', 'token_invalid');
        }
        $user = $this->users->find((int) $row['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw new ForbiddenException('Tu cuenta está deshabilitada. Contacta al administrador.');
        }
        $this->users->update((int) $user['id'], [
            'password_hash' => self::hashPassword($password),
            'email_verified_at' => $user['email_verified_at'] ?? Repository::now(),
        ]);
        $this->tokens->invalidateForUser('password_resets', (int) $user['id']);
        $this->audit->log('password_reset', (int) $user['id'], 'user', (int) $user['id'], [], $ctx);
        $this->startSession($user, false);

        return $user;
    }

    /**
     * Enlace para que el usuario defina (o restablezca) su contraseña. Usado por el administrador
     * al crear cuentas o para reenviar el acceso. Invalida los enlaces anteriores.
     *
     * @param array<string, mixed> $user
     */
    public function accessLink(array $user, int $days = 7): string
    {
        $plain = Token::random();
        $this->tokens->invalidateForUser('password_resets', (int) $user['id']);
        $this->tokens->create('password_resets', (int) $user['id'], $this->token->hash($plain), Repository::now('+' . max(1, $days) . ' days'));

        return $this->config->url('/reset-password/' . $plain);
    }

    // ------------------------------------------------------------------ Enlace mágico

    public function requestMagicLink(string $email, RequestContext $ctx): void
    {
        $email = Validator::normalizeEmail($email);
        $this->rateLimiter->hit('magic:ip:' . $ctx->ip, 10, 900);
        $this->rateLimiter->hit('magic:email:' . $email, 3, 900, 'Ya enviamos un enlace hace poco. Revisa tu bandeja de entrada (y la carpeta de spam).');
        $user = Validator::isEmail($email) ? $this->users->findByEmail($email) : null;
        if ($user === null || $user['status'] !== 'active') {
            return;
        }
        $plain = Token::random();
        $this->tokens->create('magic_links', (int) $user['id'], $this->token->hash($plain), Repository::now(self::MAGIC_TTL));
        $this->mail->send((string) $user['email'], 'Tu enlace de acceso', 'magic-link', [
            'name' => $user['name'],
            'url' => $this->config->url('/magic/' . $plain),
            'minutes' => 20,
        ]);
        $this->audit->log('magic_link_requested', (int) $user['id'], 'user', (int) $user['id'], [], $ctx);
    }

    public function magicLinkIsValid(string $plainToken): bool
    {
        return $this->tokens->findValid('magic_links', $this->token->hash($plainToken)) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeMagicLink(string $plainToken, RequestContext $ctx): array
    {
        $row = $this->tokens->findValid('magic_links', $this->token->hash($plainToken));
        if ($row === null || !$this->tokens->markUsed('magic_links', (int) $row['id'])) {
            throw new NotFoundException('Este enlace de acceso expiró o ya se usó. Solicita uno nuevo desde la pantalla de inicio de sesión.', 'token_invalid');
        }
        $user = $this->users->find((int) $row['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw new ForbiddenException('Tu cuenta está deshabilitada. Contacta al administrador.');
        }
        if ($user['email_verified_at'] === null) {
            $this->users->update((int) $user['id'], ['email_verified_at' => Repository::now()]);
        }
        $this->startSession($user, true);
        $this->audit->log('login', (int) $user['id'], 'user', (int) $user['id'], ['method' => 'magic_link'], $ctx);

        return $user;
    }

    // ------------------------------------------------------------------ Perfil

    /**
     * @param array<string, mixed> $user
     */
    public function updateProfile(array $user, string $name): void
    {
        Validator::make(['name' => $name])->required('name', 'El nombre')->maxLength('name', 150, 'El nombre')->validate();
        $this->users->update((int) $user['id'], ['name' => trim($name)]);
        $this->currentUser = false;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function changePassword(array $user, string $current, string $new, string $confirmation, RequestContext $ctx): void
    {
        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash !== '' && !password_verify($current, $hash)) {
            throw new ValidationException('La contraseña actual no es correcta.', 'validation_failed', ['fields' => ['current_password' => 'La contraseña actual no es correcta.']]);
        }
        Validator::make(['password' => $new, 'password_confirmation' => $confirmation])
            ->password('password', 'password_confirmation')
            ->validate();
        $this->users->update((int) $user['id'], ['password_hash' => self::hashPassword($new)]);
        $this->audit->log('password_changed', (int) $user['id'], 'user', (int) $user['id'], [], $ctx);
    }

    /**
     * Crea un usuario directamente (consola / seed).
     *
     * @return array<string, mixed>
     */
    public function createUser(string $email, string $name, string $password, string $role = 'user', ?int $entityId = null): array
    {
        $email = Validator::normalizeEmail($email);
        Validator::make(['email' => $email, 'name' => $name, 'password' => $password])
            ->required('email', 'El correo')->email('email')
            ->required('name', 'El nombre')
            ->password('password')
            ->validate();
        if ($this->users->findByEmail($email) !== null) {
            throw new ConflictException('Ya existe un usuario con ese correo.', 'email_taken');
        }
        $id = $this->users->create([
            'uuid' => Uuid::v4(),
            'entity_id' => $entityId,
            'name' => trim($name),
            'email' => $email,
            'password_hash' => self::hashPassword($password),
            'role' => in_array($role, ['admin', 'user', 'external'], true) ? $role : 'user',
            'email_verified_at' => Repository::now(),
            'status' => 'active',
            'created_at' => Repository::now(),
            'updated_at' => Repository::now(),
        ]);

        return (array) $this->users->find($id);
    }
}
