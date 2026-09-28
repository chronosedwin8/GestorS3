<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Archivos (metadatos; el contenido vive en S3) y sesiones de subida multipart.
 *
 * Versiones: al "reemplazar" un archivo se crea una fila nueva con previous_version_id apuntando
 * a la anterior; cuando la subida termina, la anterior recibe deleted_at + superseded_at.
 * La unicidad de nombre solo aplica a archivos vivos y listos (columna generada `alive`).
 */
final class CreateFileTables extends AbstractMigration
{
    private const OPTS = ['id' => false, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];

    public function up(): void
    {
        $this->table('files', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('folder_id', 'biginteger', ['signed' => false])
            ->addColumn('uploaded_by', 'biginteger', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('extension', 'string', ['limit' => 32, 'default' => ''])
            ->addColumn('mime_type', 'string', ['limit' => 127, 'default' => 'application/octet-stream'])
            ->addColumn('size_bytes', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('s3_key', 'string', ['limit' => 512])
            ->addColumn('etag', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sha256', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['uploading', 'ready', 'failed'], 'default' => 'uploading'])
            ->addColumn('version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('previous_version_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('superseded_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['s3_key'], ['unique' => true])
            ->addIndex(['folder_id', 'status'])
            ->addIndex(['uploaded_by'])
            ->addIndex(['status', 'created_at'])
            ->addForeignKey('folder_id', 'folders', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->addForeignKey('previous_version_id', 'files', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->execute("ALTER TABLE files ADD COLUMN alive TINYINT GENERATED ALWAYS AS (IF(deleted_at IS NULL AND status = 'ready', 1, NULL)) STORED");
        $this->execute('ALTER TABLE files ADD UNIQUE INDEX files_unique_live_name (folder_id, name, alive)');

        $this->table('upload_sessions', self::OPTS)
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('uuid', 'char', ['limit' => 36])
            ->addColumn('file_id', 'biginteger', ['signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('s3_upload_id', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('part_size', 'biginteger', ['signed' => false])
            ->addColumn('total_parts', 'integer', ['signed' => false])
            ->addColumn('completed_parts', 'json', ['null' => true])
            ->addColumn('status', 'enum', ['values' => ['active', 'completed', 'aborted'], 'default' => 'active'])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['uuid'], ['unique' => true])
            ->addIndex(['file_id'])
            ->addIndex(['status', 'created_at'])
            ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('upload_sessions')->drop()->save();
        $this->table('files')->drop()->save();
    }
}
