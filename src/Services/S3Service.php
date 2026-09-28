<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StorageException;
use App\Support\Config;
use App\Support\ContentDisposition;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Acceso a Amazon S3 (o compatible). Los archivos nunca pasan por PHP salvo en la descarga ZIP.
 * Claves de objeto: {S3_PREFIX/}{root_folder_uuid}/{file_uuid}
 */
final class S3Service
{
    public function __construct(
        private readonly S3Client $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function bucket(): string
    {
        $bucket = $this->config->string('s3.bucket');
        if ($bucket === '') {
            throw new StorageException('El almacenamiento no está configurado (falta S3_BUCKET). Contacta al administrador.', 'storage_not_configured');
        }

        return $bucket;
    }

    public function key(string $rootUuid, string $fileUuid): string
    {
        $prefix = $this->config->string('s3.prefix');

        return ($prefix !== '' ? $prefix . '/' : '') . $rootUuid . '/' . $fileUuid;
    }

    /**
     * URL prefirmada para subir un objeto con un único PUT.
     *
     * @return array{url: string, headers: array<string, string>, expiresIn: int}
     */
    public function presignPut(string $key, string $contentType, string $originalName): array
    {
        $ttl = $this->config->int('s3.upload_ttl', 1800);

        return $this->call(function () use ($key, $contentType, $originalName, $ttl): array {
            $command = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'ContentType' => $contentType,
                'Metadata' => ['original-name' => rawurlencode($originalName)],
            ]);
            $request = $this->client->createPresignedRequest($command, '+' . $ttl . ' seconds');
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $lower = strtolower($name);
                if ($lower === 'host' || $lower === 'content-length' || $lower === 'user-agent' || $lower === 'aws-sdk-invocation-id' || $lower === 'aws-sdk-retry') {
                    continue;
                }
                $headers[$name] = implode(', ', $values);
            }

            return ['url' => (string) $request->getUri(), 'headers' => $headers, 'expiresIn' => $ttl];
        }, 'presign_put');
    }

    public function createMultipart(string $key, string $contentType, string $originalName): string
    {
        return $this->call(function () use ($key, $contentType, $originalName): string {
            $result = $this->client->createMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'ContentType' => $contentType,
                'Metadata' => ['original-name' => rawurlencode($originalName)],
            ]);

            return (string) $result['UploadId'];
        }, 'create_multipart');
    }

    public function presignPart(string $key, string $uploadId, int $partNumber): string
    {
        $ttl = $this->config->int('s3.upload_ttl', 1800);

        return $this->call(function () use ($key, $uploadId, $partNumber, $ttl): string {
            $command = $this->client->getCommand('UploadPart', [
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ]);

            return (string) $this->client->createPresignedRequest($command, '+' . $ttl . ' seconds')->getUri();
        }, 'presign_part');
    }

    /**
     * Partes ya subidas de un multipart (para reanudar).
     *
     * @return list<array{partNumber: int, etag: string, size: int}>
     */
    public function listParts(string $key, string $uploadId): array
    {
        return $this->call(function () use ($key, $uploadId): array {
            $parts = [];
            $marker = 0;
            do {
                $result = $this->client->listParts([
                    'Bucket' => $this->bucket(),
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'MaxParts' => 1000,
                    'PartNumberMarker' => $marker,
                ]);
                foreach ($result['Parts'] ?? [] as $part) {
                    $parts[] = ['partNumber' => (int) $part['PartNumber'], 'etag' => (string) $part['ETag'], 'size' => (int) $part['Size']];
                }
                $truncated = (bool) ($result['IsTruncated'] ?? false);
                $marker = (int) ($result['NextPartNumberMarker'] ?? 0);
            } while ($truncated && $marker > 0);

            return $parts;
        }, 'list_parts');
    }

    /**
     * @param list<array{partNumber: int, etag: string}> $parts
     */
    public function completeMultipart(string $key, string $uploadId, array $parts): ?string
    {
        return $this->call(function () use ($key, $uploadId, $parts): ?string {
            $result = $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => array_map(static fn (array $p): array => ['PartNumber' => $p['partNumber'], 'ETag' => $p['etag']], $parts),
                ],
            ]);

            return isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null;
        }, 'complete_multipart');
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                $this->logger->warning('No se pudo abortar el multipart', ['key' => $key, 'error' => $e->getAwsErrorMessage()]);
            }
        }
    }

    /**
     * Metadatos del objeto o null si no existe.
     *
     * @return array{size: int, etag: string, contentType: string}|null
     */
    public function head(string $key): ?array
    {
        try {
            $result = $this->client->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (AwsException $e) {
            if (in_array($e->getStatusCode(), [403, 404], true) || in_array($e->getAwsErrorCode(), ['NotFound', 'NoSuchKey'], true)) {
                return null;
            }
            $this->logger->error('Error en HeadObject', ['key' => $key, 'error' => $e->getMessage()]);
            throw new StorageException();
        }

        return [
            'size' => (int) ($result['ContentLength'] ?? 0),
            'etag' => trim((string) ($result['ETag'] ?? ''), '"'),
            'contentType' => (string) ($result['ContentType'] ?? ''),
        ];
    }

    /**
     * URL prefirmada de descarga (attachment) o de vista (inline).
     */
    public function presignGet(string $key, string $filename, bool $inline = false, ?string $contentType = null): string
    {
        $ttl = $this->config->int('s3.download_ttl', 600);

        return $this->call(function () use ($key, $filename, $inline, $contentType, $ttl): string {
            $params = [
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'ResponseContentDisposition' => ContentDisposition::build($filename, $inline),
                'ResponseCacheControl' => 'private, max-age=' . $ttl,
            ];
            if ($contentType !== null) {
                $params['ResponseContentType'] = $contentType;
            }
            $command = $this->client->getCommand('GetObject', $params);

            return (string) $this->client->createPresignedRequest($command, '+' . $ttl . ' seconds')->getUri();
        }, 'presign_get');
    }

    /**
     * Stream de lectura del objeto (sin cargarlo en memoria) para el ZIP.
     */
    public function stream(string $key): StreamInterface
    {
        return $this->call(function () use ($key): StreamInterface {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                '@http' => ['stream' => true],
            ]);

            return $result['Body'];
        }, 'get_object');
    }

    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (AwsException $e) {
            $this->logger->warning('No se pudo eliminar el objeto', ['key' => $key, 'error' => $e->getAwsErrorMessage()]);
        }
    }

    public function client(): S3Client
    {
        return $this->client;
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function call(callable $fn, string $operation): mixed
    {
        try {
            return $fn();
        } catch (AwsException $e) {
            $this->logger->error('Error de S3', [
                'operation' => $operation,
                'code' => $e->getAwsErrorCode(),
                'status' => $e->getStatusCode(),
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ]);
            $code = (string) $e->getAwsErrorCode();
            $message = match ($code) {
                'NoSuchBucket' => 'El bucket de almacenamiento no existe. Un administrador debe revisar S3_BUCKET o ejecutar "php bin/console s3:setup".',
                'InvalidAccessKeyId', 'SignatureDoesNotMatch' => 'Las credenciales de almacenamiento no son válidas. Un administrador debe revisar AWS_ACCESS_KEY_ID y AWS_SECRET_ACCESS_KEY.',
                'AccessDenied' => 'El almacenamiento rechazó la operación (acceso denegado). Un administrador debe revisar la política IAM.',
                'InvalidPart', 'InvalidPartOrder' => 'Algunas partes del archivo no llegaron correctamente. Vuelve a intentar la subida.',
                'NoSuchUpload' => 'La subida ya no existe en el almacenamiento (pudo expirar). Vuelve a subir el archivo.',
                default => 'No pudimos comunicarnos con el almacenamiento. Inténtalo de nuevo en unos segundos.',
            };

            throw new StorageException($message, 'storage_' . ($code !== '' ? strtolower($code) : 'error'));
        } catch (\Aws\Exception\CredentialsException $e) {
            $this->logger->error('Credenciales de S3 ausentes', ['error' => $e->getMessage()]);

            throw new StorageException('El almacenamiento no tiene credenciales configuradas. Contacta al administrador.', 'storage_not_configured');
        }
    }
}
