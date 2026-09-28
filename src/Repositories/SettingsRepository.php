<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends Repository
{
    /**
     * @return array<string, string|null>
     */
    public function allValues(): array
    {
        $out = [];
        foreach ($this->all('SELECT `key`, `value` FROM settings') as $row) {
            $out[(string) $row['key']] = $row['value'] === null ? null : (string) $row['value'];
        }

        return $out;
    }

    public function set(string $key, ?string $value): void
    {
        $this->exec(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $value, self::now()],
        );
    }

    public function delete(string $key): void
    {
        $this->exec('DELETE FROM settings WHERE `key` = ?', [$key]);
    }
}
