<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use Ramsey\Uuid\Uuid;

/**
 * Crea un administrador inicial a partir de SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD (útil en CI o despliegues
 * automatizados). Para uso interactivo se recomienda: php bin/console user:create-admin
 */
final class AdminUserSeeder extends AbstractSeed
{
    public function run(): void
    {
        $email = strtolower(trim((string) (getenv('SEED_ADMIN_EMAIL') ?: ($_ENV['SEED_ADMIN_EMAIL'] ?? ''))));
        $password = (string) (getenv('SEED_ADMIN_PASSWORD') ?: ($_ENV['SEED_ADMIN_PASSWORD'] ?? ''));
        if ($email === '' || strlen($password) < 8) {
            $this->getOutput()->writeln('<comment>Define SEED_ADMIN_EMAIL y SEED_ADMIN_PASSWORD (mín. 8) o usa: php bin/console user:create-admin</comment>');

            return;
        }
        $exists = $this->fetchRow(sprintf("SELECT id FROM users WHERE email = '%s'", addslashes($email)));
        if ($exists) {
            $this->execute(sprintf("UPDATE users SET role = 'admin', status = 'active' WHERE email = '%s'", addslashes($email)));

            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->table('users')->insert([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => (string) (getenv('SEED_ADMIN_NAME') ?: 'Administrador'),
            'email' => $email,
            'password_hash' => password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->saveData();
    }
}
