<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Transforma filas de base de datos en estructuras públicas para la API (sin IDs internos).
 */
final class Present
{
    private const KINDS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff', 'heic', 'avif', 'ico', 'psd', 'ai', 'eps'],
        'video' => ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'm4v', 'mpg', 'mpeg', 'ogv', '3gp'],
        'audio' => ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'wma', 'opus', 'mid', 'midi'],
        'pdf' => ['pdf'],
        'doc' => ['doc', 'docx', 'odt', 'rtf', 'pages'],
        'sheet' => ['xls', 'xlsx', 'ods', 'csv', 'tsv', 'numbers', 'xlsm'],
        'slides' => ['ppt', 'pptx', 'odp', 'key'],
        'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'iso', 'dmg'],
        'code' => ['html', 'htm', 'css', 'js', 'ts', 'json', 'xml', 'php', 'py', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rb', 'sql', 'sh', 'yml', 'yaml', 'bat', 'ps1'],
        'text' => ['txt', 'md', 'log', 'ini', 'conf', 'env'],
    ];

    public static function kind(string $extension): string
    {
        foreach (self::KINDS as $kind => $exts) {
            if (in_array($extension, $exts, true)) {
                return $kind;
            }
        }

        return 'file';
    }

    public static function iso(mixed $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $datetime, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function file(array $row): array
    {
        $ext = (string) ($row['extension'] ?? '');
        $mime = (string) ($row['mime_type'] ?? '');

        return [
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'extension' => $ext,
            'mime' => $mime,
            'size' => (int) ($row['size_bytes'] ?? 0),
            'kind' => self::kind($ext),
            'previewable' => FileName::previewKind($mime, $ext) !== null,
            'version' => (int) ($row['version'] ?? 1),
            'uploadedBy' => $row['uploader_name'] ?? null,
            'createdAt' => self::iso($row['created_at'] ?? null),
            'updatedAt' => self::iso($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function folder(array $row): array
    {
        $out = [
            'uuid' => $row['uuid'],
            'name' => $row['name'],
        ];
        foreach (['description' => 'description', 'owner_name' => 'ownerName', 'owner_entity' => 'ownerEntity', 'permission' => 'permission', 'path_cache' => 'path'] as $from => $to) {
            if (array_key_exists($from, $row)) {
                $out[$to] = $row[$from];
            }
        }
        foreach (['files_count' => 'filesCount', 'size_bytes' => 'size'] as $from => $to) {
            if (array_key_exists($from, $row)) {
                $out[$to] = (int) $row[$from];
            }
        }
        $out['createdAt'] = self::iso($row['created_at'] ?? null);
        $out['updatedAt'] = self::iso($row['updated_at'] ?? null);
        if (array_key_exists('last_activity_at', $row) || array_key_exists('last_file_at', $row)) {
            $last = (string) ($row['last_activity_at'] ?? $row['last_file_at'] ?? '');
            $out['lastActivityAt'] = self::iso(max($last, (string) ($row['updated_at'] ?? '')));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function member(array $row): array
    {
        return [
            'userUuid' => $row['user_uuid'],
            'name' => $row['name'],
            'email' => $row['email'],
            'entity' => $row['entity_name'] ?? null,
            'permission' => $row['permission'],
            'status' => $row['status'] ?? 'active',
            'since' => self::iso($row['created_at'] ?? null),
        ];
    }
}
