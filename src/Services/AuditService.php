<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\Repository;
use App\Support\RequestContext;
use Psr\Log\LoggerInterface;

/**
 * Registro de actividad (audit_log). Nunca debe interrumpir la operación principal.
 */
final class AuditService
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function log(
        string $action,
        ?int $userId,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        ?RequestContext $ctx = null,
        ?int $shareLinkId = null,
    ): void {
        try {
            $this->repository->create([
                'user_id' => $userId,
                'share_link_id' => $shareLinkId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'ip' => $ctx->ip ?? '',
                'user_agent' => $ctx->userAgent ?? '',
                'created_at' => Repository::now(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo registrar la auditoría', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
