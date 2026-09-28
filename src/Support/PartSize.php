<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cálculo del tamaño de parte para S3 Multipart Upload.
 * S3 exige partes >= 5 MB (salvo la última) y un máximo de 10 000 partes.
 */
final class PartSize
{
    public const MAX_PARTS = 10000;
    public const S3_MIN_PART = 5 * Bytes::MB;

    /**
     * @return array{partSize: int, totalParts: int}
     */
    public static function calculate(int $fileSize, int $minPartSize = 8 * Bytes::MB): array
    {
        if ($fileSize <= 0) {
            throw new \InvalidArgumentException('El tamaño del archivo debe ser positivo.');
        }
        $partSize = max($minPartSize, self::S3_MIN_PART);
        $needed = (int) ceil($fileSize / self::MAX_PARTS);
        if ($needed > $partSize) {
            // Redondear hacia arriba al siguiente MB.
            $partSize = (int) (ceil($needed / Bytes::MB) * Bytes::MB);
        }

        return [
            'partSize' => $partSize,
            'totalParts' => (int) ceil($fileSize / $partSize),
        ];
    }
}
