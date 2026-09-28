<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Envoltura de la sesión nativa de PHP: arranque seguro, CSRF y mensajes flash.
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            // Pruebas y consola: sesión en memoria, sin cookies.
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            $this->started = true;

            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }
        $path = $this->config->string('session.path');
        if ($path !== '' && is_dir($path) && is_writable($path)) {
            session_save_path($path);
        }
        $lifetime = max(5, $this->config->int('session.lifetime_min', 480)) * 60;
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('fs_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => ($this->config->basePath() !== '' ? $this->config->basePath() : '') . '/',
            'secure' => $this->config->bool('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'fs_session', '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
        $this->started = false;
    }

    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf', $token);
        }

        return $token;
    }

    public function validCsrf(?string $token): bool
    {
        $expected = $this->get('_csrf');

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public function flash(string $type, string $message): void
    {
        $flashes = $this->get('_flash', []);
        $flashes = is_array($flashes) ? $flashes : [];
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flashes);
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function consumeFlashes(): array
    {
        $flashes = $this->get('_flash', []);
        $this->remove('_flash');

        return is_array($flashes) ? array_values($flashes) : [];
    }

    /**
     * Guarda valores de formulario para re-mostrarlos tras un error.
     *
     * @param array<string, mixed> $old
     */
    public function flashOld(array $old): void
    {
        unset($old['password'], $old['password_confirmation'], $old['current_password'], $old['_csrf']);
        $this->set('_old', $old);
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeOld(): array
    {
        $old = $this->get('_old', []);
        $this->remove('_old');

        return is_array($old) ? $old : [];
    }
}
