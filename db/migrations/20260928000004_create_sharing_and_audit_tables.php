<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Enlaces públicos, registro de auditoría, ajustes del administrador y contadores de rate limiting.
 */
final class CreateSharingAndAuditTables extends AbstractMigration
{
    private const OPTS = ['id' => false, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];

    public function up(): void
    {
        $this->table('share_links', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('folder_id', 'biginteger', ['signed' => false])
            ->addColumn('created_by', 'biginteger', ['signed' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addColumn('max_downloads', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('downloads', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['folder_id'])
            ->addForeignKey('folder_id', 'folders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('created_by', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('audit_log', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('share_link_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 64])
            ->addColumn('target_type', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('target_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('meta', 'json', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'default' => ''])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['created_at'])
            ->addIndex(['target_type', 'target_id'])
            ->addIndex(['user_id'])
            ->addIndex(['action'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('share_link_id', 'share_links', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        // Tablas con clave primaria textual: SQL explícito dentro de la migración.
        $this->execute(<<<'SQL'
            CREATE TABLE settings (
                `key` VARCHAR(64) NOT NULL,
                `value` TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE rate_limits (
                rkey VARCHAR(191) NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                reset_at DATETIME NOT NULL,
                PRIMARY KEY (rkey),
                KEY rate_limits_reset_at (reset_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS rate_limits');
        $this->execute('DROP TABLE IF EXISTS settings');
        $this->table('audit_log')->drop()->save();
        $this->table('share_links')->drop()->save();
    }
}
