<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tipo de cuenta "external": personas ajenas a la institución que solo trabajan en carpetas compartidas.
 * Las invitaciones guardan el tipo de cuenta que tendrá el invitado al registrarse.
 */
final class AddExternalRole extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE users MODIFY role ENUM('admin','user','external') NOT NULL DEFAULT 'user'");
        $this->execute("ALTER TABLE invitations ADD COLUMN role ENUM('user','external') NULL AFTER permission");
    }

    public function down(): void
    {
        $this->execute("UPDATE users SET role = 'user' WHERE role = 'external'");
        $this->execute("ALTER TABLE users MODIFY role ENUM('admin','user') NOT NULL DEFAULT 'user'");
        $this->execute('ALTER TABLE invitations DROP COLUMN role');
    }
}
