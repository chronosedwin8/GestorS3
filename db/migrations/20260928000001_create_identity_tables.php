<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Entidades, usuarios y tokens de acceso (magic links y restablecimiento de contraseña).
 */
final class CreateIdentityTables extends AbstractMigration
{
    private const OPTS = ['id' => false, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];

    public function change(): void
    {
        $this->table('entities', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('domain', 'string', ['limit' => 190, 'null' => true])
            ->addColumn('logo_key', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['name'], ['unique' => true])
            ->create();

        $this->table('users', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('entity_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('role', 'enum', ['values' => ['admin', 'user'], 'default' => 'user'])
            ->addColumn('email_verified_at', 'datetime', ['null' => true])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('status', 'enum', ['values' => ['active', 'disabled'], 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['entity_id'])
            ->addForeignKey('entity_id', 'entities', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        foreach (['magic_links', 'password_resets'] as $tableName) {
            $this->table($tableName, self::OPTS)
                ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
                ->addColumn('user_id', 'biginteger', ['signed' => false])
                ->addColumn('token_hash', 'char', ['limit' => 64])
                ->addColumn('expires_at', 'datetime')
                ->addColumn('used_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['token_hash'], ['unique' => true])
                ->addIndex(['user_id'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }
}
