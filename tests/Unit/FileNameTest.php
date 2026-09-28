<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sanitizeCases(): array
    {
        return [
            'conserva acentos y espacios' => ['Informe de gestión 2026.pdf', 'Informe de gestión 2026.pdf'],
            'quita caracteres de control' => ["Hola\x00\x07mundo\n.txt", 'Holamundo.txt'],
            'reemplaza separadores de ruta' => ['../../etc/passwd', '..-..-etc-passwd'],
            'barra invertida' => ['C:\\Users\\a.txt', 'C:-Users-a.txt'],
            'colapsa espacios' => ['  muchos    espacios   .doc', 'muchos espacios .doc'],
            'quita puntos finales' => ['archivo...', 'archivo'],
            'punto solo es inválido' => ['.', ''],
            'dos puntos es inválido' => ['..', ''],
            'vacío' => ['   ', ''],
            'emoji permitido' => ['foto 📷.jpg', 'foto 📷.jpg'],
        ];
    }

    #[DataProvider('sanitizeCases')]
    public function testSanitize(string $input, string $expected): void
    {
        self::assertSame($expected, FileName::sanitize($input));
    }

    public function testTruncateKeepsExtensionAndByteLimit(): void
    {
        $name = str_repeat('ñ', 300) . '.xlsx';
        $clean = FileName::sanitize($name);
        self::assertLessThanOrEqual(255, strlen($clean));
        self::assertStringEndsWith('.xlsx', $clean);
        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
    }

    public function testExtension(): void
    {
        self::assertSame('pdf', FileName::extension('Documento.PDF'));
        self::assertSame('gz', FileName::extension('backup.tar.gz'));
        self::assertSame('', FileName::extension('.env'));
        self::assertSame('', FileName::extension('LEEME'));
        self::assertSame('', FileName::extension('nota. con espacio'));
    }

    public function testWithCounter(): void
    {
        self::assertSame('informe (2).pdf', FileName::withCounter('informe.pdf', 2));
        self::assertSame('informe (3).pdf', FileName::withCounter('informe (2).pdf', 3));
        self::assertSame('LEEME (2)', FileName::withCounter('LEEME', 2));
    }

    public function testDirectorySegments(): void
    {
        self::assertSame(['Proyecto', 'Datos'], FileName::directorySegments('Proyecto/Datos/archivo.bin', 'archivo.bin'));
        self::assertSame(['A', 'B'], FileName::directorySegments('A\\B\\c.txt', 'c.txt'));
        self::assertSame(['etc'], FileName::directorySegments('../../etc/passwd', 'passwd'));
        self::assertSame([], FileName::directorySegments('', 'x.txt'));
        self::assertSame(['Solo'], FileName::directorySegments('Solo'));
    }

    public function testPreviewKind(): void
    {
        self::assertSame('pdf', FileName::previewKind('application/pdf', 'pdf'));
        self::assertSame('image', FileName::previewKind('image/png', 'png'));
        self::assertSame('video', FileName::previewKind('video/mp4', 'mp4'));
        self::assertSame('audio', FileName::previewKind('audio/mpeg', 'mp3'));
        self::assertSame('text', FileName::previewKind('text/plain', 'txt'));
        self::assertNull(FileName::previewKind('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'));
        self::assertNull(FileName::previewKind('image/tiff', 'tif'));
    }

    public function testNormalizeMime(): void
    {
        self::assertSame('application/pdf', FileName::normalizeMime('Application/PDF'));
        self::assertSame('application/octet-stream', FileName::normalizeMime(''));
        self::assertSame('application/octet-stream', FileName::normalizeMime('text/html; <script>'));
    }
}
