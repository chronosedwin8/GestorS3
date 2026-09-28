<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FolderMemberRepository;
use App\Repositories\FolderRepository;
use App\Support\Config;

/**
 * Notificaciones por correo a los miembros de una carpeta.
 */
final class NotificationService
{
    public function __construct(
        private readonly FolderMemberRepository $members,
        private readonly FolderRepository $folders,
        private readonly MailService $mail,
        private readonly Config $config,
    ) {
    }

    /**
     * "Se subieron N archivos a X".
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $folder
     */
    public function filesUploaded(array $actor, array $folder, int $count): bool
    {
        $root = $folder['parent_id'] === null ? $folder : $this->folders->find((int) $folder['root_id']);
        if ($root === null) {
            return false;
        }
        $recipients = $this->members->recipients((int) $root['id'], (int) $actor['id']);
        $sent = false;
        $subject = sprintf('%s subió %d %s a "%s"', $actor['name'], $count, $count === 1 ? 'archivo' : 'archivos', $root['name']);
        foreach (array_slice($recipients, 0, 200) as $recipient) {
            $sent = $this->mail->send((string) $recipient['email'], $subject, 'files-uploaded', [
                'name' => $recipient['name'],
                'actorName' => $actor['name'],
                'count' => $count,
                'folderName' => $folder['name'],
                'folderPath' => $folder['path_cache'] ?? $folder['name'],
                'url' => $this->config->url('/folders/' . $folder['uuid']),
            ]) || $sent;
        }

        return $sent;
    }
}
