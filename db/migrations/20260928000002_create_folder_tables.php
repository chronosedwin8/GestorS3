<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Carpetas, miembros (permisos en la carpeta raíz) e invitaciones.
 *
 * Nota sobre unicidad: MySQL trata los NULL como distintos en índices únicos, por lo que
 * UNIQUE(parent_id, name, deleted_at) no impediría duplicados vivos (deleted_at = NULL).
 * Se usa una columna generada `alive` (1 si está viva, NULL si está borrada) en su lugar.
 */
final class CreateFolderTables extends AbstractMigration
{
    private const OPTS = ['id' => false, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];

    public function up(): void
    {
        $this->table('folders', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('parent_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('root_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('owner_id', 'biginteger', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('description', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('path_cache', 'string', ['limit' => 1024, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['parent_id'])
            ->addIndex(['root_id'])
            ->addIndex(['owner_id'])
            ->addForeignKey('parent_id', 'folders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('root_id', 'folders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('owner_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->create();

        $this->execute('ALTER TABLE folders ADD COLUMN alive TINYINT GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED');
        $this->execute('ALTER TABLE folders ADD UNIQUE INDEX folders_unique_live_name (parent_id, name, alive)');

        $this->table('folder_members', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('folder_id', 'biginteger', ['signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('permission', 'enum', ['values' => ['owner', 'editor', 'viewer']])
            ->addColumn('added_by', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['folder_id', 'user_id'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addForeignKey('folder_id', 'folders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('added_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('invitations', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('entity_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('invited_by', 'biginteger', ['signed' => false])
            ->addColumn('folder_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('permission', 'enum', ['values' => ['editor', 'viewer'], 'null' => true])
            ->addColumn('token_hash', 'char', ['limit' => 64])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('accepted_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['email'])
            ->addIndex(['folder_id'])
            ->addForeignKey('entity_id', 'entities', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('invited_by', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('folder_id', 'folders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('invitations')->drop()->save();
        $this->table('folder_members')->drop()->save();
        $this->table('folders')->drop()->save();
    }
}
