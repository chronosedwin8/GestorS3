<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generación y verificación de tokens opacos (invitaciones, enlaces mágicos, restablecimientos, enlaces públicos).
 * En base de datos solo se guarda el HMAC del token, nunca el token en claro.
 */
final class Token
{
    public function __construct(private readonly string $appKey)
    {
        if ($this->appKey === '') {
            throw new \RuntimeException('APP_KEY no está configurada. Genera una con: php -r "echo base64_encode(random_bytes(32));"');
        }
    }

    public static function random(int $bytes = 32): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->appKey);
    }

    /**
     * Token determinista derivado de un identificador (permite volver a mostrar enlaces públicos sin guardarlos en claro).
     */
    public function derive(string $purpose, string $id): string
    {
        return self::base64Url(hash_hmac('sha256', $purpose . ':' . $id, $this->appKey, true));
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
