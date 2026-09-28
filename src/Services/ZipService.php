<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LimitExceededException;
use App\Exceptions\ValidationException;
use App\Repositories\FileRepository;
use App\Repositories\FolderRepository;
use App\Support\Bytes;
use App\Support\Uuid;
use Psr\Log\LoggerInterface;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Descarga de carpetas / selecciones como ZIP en streaming: se lee de S3 y se escribe a la salida
 * sin tocar el disco del servidor.
 */
final class ZipService
{
    public function __construct(
        private readonly FolderRepository $folders,
        private readonly FileRepository $files,
        private readonly S3Service $s3,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Calcula las entradas del ZIP. Sin selección: toda la carpeta (recursivo).
     *
     * @param array<string, mixed> $folder
     * @param list<string> $fileUuids
     * @param list<string> $folderUuids
     *
     * @return array{name: string, entries: list<array{path: string, key: string, size: int, id: int, modified: string}>, totalBytes: int, count: int}
     */
    public function plan(array $folder, array $fileUuids = [], array $folderUuids = []): array
    {
        $fileUuids = array_values(array_filter($fileUuids, [Uuid::class, 'isValid']));
        $folderUuids = array_values(array_filter($folderUuids, [Uuid::class, 'isValid']));
        $entries = [];
        if ($fileUuids === [] && $folderUuids === []) {
            $entries = $this->treeEntries((int) $folder['id'], '');
        } else {
            foreach ($this->files->findReadyByUuids($fileUuids, [(int) $folder['id']]) as $file) {
                $entries[] = $this->entry($file, (string) $file['name']);
            }
            foreach ($folderUuids as $uuid) {
                $child = $this->folders->findByUuid($uuid);
                if ($child !== null && (int) $child['parent_id'] === (int) $folder['id']) {
                    $entries = array_merge($entries, $this->treeEntries((int) $child['id'], $child['name'] . '/'));
                }
            }
        }
        if ($entries === []) {
            throw new ValidationException('No hay archivos para descargar en esta selección.', 'zip_empty');
        }
        $total = array_sum(array_map(static fn (array $e): int => $e['size'], $entries));

        return ['name' => (string) $folder['name'], 'entries' => $entries, 'totalBytes' => $total, 'count' => count($entries)];
    }

    /**
     * @param array{name: string, entries: list<array<string, mixed>>, totalBytes: int, count: int} $plan
     */
    public function assertWithinLimits(array $plan): void
    {
        $maxBytes = $this->settings->zipMaxBytes();
        $maxFiles = $this->settings->zipMaxFiles();
        if ($plan['totalBytes'] > $maxBytes || $plan['count'] > $maxFiles) {
            throw new LimitExceededException(
                sprintf(
                    'Esta descarga tiene %s en %s archivos y supera el límite de %s o %s archivos por ZIP. Descarga por subcarpetas o selecciona menos archivos.',
                    Bytes::human($plan['totalBytes']),
                    number_format($plan['count'], 0, ',', '.'),
                    Bytes::human($maxBytes),
                    number_format($maxFiles, 0, ',', '.'),
                ),
                'zip_too_large',
                ['totalBytes' => $plan['totalBytes'], 'count' => $plan['count']],
            );
        }
    }

    /**
     * @return list<array{path: string, key: string, size: int, id: int, modified: string}>
     */
    private function treeEntries(int $folderId, string $prefix): array
    {
        $tree = $this->folders->subtree($folderId);
        $paths = [];
        foreach ($tree as $node) {
            $id = (int) $node['id'];
            if ($id === $folderId) {
                $paths[$id] = $prefix;
                continue;
            }
            $parentPath = $paths[(int) $node['parent_id']] ?? $prefix;
            $paths[$id] = $parentPath . $node['name'] . '/';
        }
        $entries = [];
        foreach ($this->files->readyInFolders(array_keys($paths)) as $file) {
            $entries[] = $this->entry($file, $paths[(int) $file['folder_id']] . $file['name']);
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{path: string, key: string, size: int, id: int, modified: string}
     */
    private function entry(array $file, string $path): array
    {
        return [
            'path' => $path,
            'key' => (string) $file['s3_key'],
            'size' => (int) $file['size_bytes'],
            'id' => (int) $file['id'],
            'modified' => (string) $file['created_at'],
        ];
    }

    /**
     * Envía el ZIP directamente a la salida (cabeceras incluidas).
     *
     * @param array{name: string, entries: list<array<string, mixed>>, totalBytes: int, count: int} $plan
     */
    public function stream(array $plan): void
    {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $zip = new ZipStream(
            outputName: $plan['name'] . '.zip',
            sendHttpHeaders: true,
            contentType: 'application/zip',
            defaultCompressionMethod: CompressionMethod::STORE,
            defaultEnableZeroHeader: true,
            enableZip64: true,
            flushOutput: true,
        );
        $errors = [];
        foreach ($plan['entries'] as $entry) {
            if (connection_aborted() === 1) {
                $this->logger->info('Descarga ZIP cancelada por el cliente', ['name' => $plan['name']]);

                return;
            }
            try {
                $stream = $this->s3->stream((string) $entry['key']);
                $zip->addFileFromPsr7Stream(
                    fileName: (string) $entry['path'],
                    stream: $stream,
                    lastModificationDateTime: new \DateTimeImmutable((string) $entry['modified'], new \DateTimeZone('UTC')),
                );
                $stream->close();
            } catch (\Throwable $e) {
                $this->logger->error('Error agregando archivo al ZIP', ['path' => $entry['path'], 'error' => $e->getMessage()]);
                $errors[] = (string) $entry['path'];
            }
        }
        if ($errors !== []) {
            $zip->addFile(
                fileName: 'ARCHIVOS_CON_ERROR.txt',
                data: "No se pudieron incluir estos archivos. Descárgalos de forma individual:\r\n\r\n" . implode("\r\n", $errors) . "\r\n",
            );
        }
        $zip->finish();
    }
}
