<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends Repository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $this->insert('audit_log', $data);
    }

    /**
     * Descargas de un archivo (para el panel de detalle).
     *
     * @return list<array<string, mixed>>
     */
    public function downloadsOfFile(int $fileId, int $limit = 50): array
    {
        return $this->all(
            "SELECT a.created_at, a.action, a.ip, u.name AS user_name, u.email AS user_email,
                    a.share_link_id IS NOT NULL AS via_link
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.target_type = 'file' AND a.target_id = ? AND a.action IN ('download', 'preview')
             ORDER BY a.created_at DESC LIMIT $limit",
            [$fileId],
        );
    }

    /**
     * @param array{action?: string, user?: string, from?: string, to?: string} $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = $filters['action'];
        }
        if (($filters['user'] ?? '') !== '') {
            $where[] = '(u.email LIKE :user1 OR u.name LIKE :user2)';
            $params['user1'] = $params['user2'] = '%' . self::escapeLike((string) $filters['user']) . '%';
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'a.created_at < :to';
            $params['to'] = $filters['to'];
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
        $total = (int) $this->scalar("SELECT COUNT(*) $from WHERE $whereSql", $params);
        $items = $this->all(
            "SELECT a.*, u.name AS user_name, u.email AS user_email $from WHERE $whereSql ORDER BY a.id DESC LIMIT $limit OFFSET $offset",
            $params,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<string>
     */
    public function distinctActions(): array
    {
        return array_map(static fn (array $r): string => (string) $r['action'], $this->all('SELECT DISTINCT action FROM audit_log ORDER BY action'));
    }
}
