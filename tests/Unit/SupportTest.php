<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MailService;
use App\Services\SettingsService;
use App\Support\Bytes;
use App\Support\ContentDisposition;
use App\Support\PartSize;
use App\Support\Present;
use App\Support\Token;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testPartSizeSmallFile(): void
    {
        $plan = PartSize::calculate(20 * Bytes::MB);
        self::assertSame(8 * Bytes::MB, $plan['partSize']);
        self::assertSame(3, $plan['totalParts']);
    }

    public function testPartSizeNeverExceedsTenThousandParts(): void
    {
        foreach ([5 * Bytes::GB, 100 * Bytes::GB, 5 * 1024 * Bytes::GB] as $size) {
            $plan = PartSize::calculate($size);
            self::assertLessThanOrEqual(PartSize::MAX_PARTS, $plan['totalParts']);
            self::assertGreaterThanOrEqual(8 * Bytes::MB, $plan['partSize']);
            self::assertGreaterThanOrEqual($size, $plan['partSize'] * $plan['totalParts']);
        }
    }

    public function testPartSizeRespectsS3Minimum(): void
    {
        self::assertSame(PartSize::S3_MIN_PART, PartSize::calculate(100 * Bytes::MB, 1 * Bytes::MB)['partSize']);
    }

    public function testPartSizeRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PartSize::calculate(0);
    }

    public function testBytesHuman(): void
    {
        self::assertSame('0 B', Bytes::human(0));
        self::assertSame('1 KB', Bytes::human(1024));
        self::assertSame('1,5 MB', Bytes::human(1.5 * Bytes::MB));
        self::assertSame('5 GB', Bytes::human(5 * Bytes::GB));
    }

    public function testContentDispositionUtf8(): void
    {
        $header = ContentDisposition::build('Informe año 2026 "final".pdf');
        self::assertStringStartsWith('attachment; filename="', $header);
        self::assertStringContainsString("filename*=UTF-8''Informe%20a%C3%B1o%202026%20%22final%22.pdf", $header);
        self::assertStringNotContainsString('"final"', $header);
        self::assertStringStartsWith('inline;', ContentDisposition::build('a.pdf', true));
    }

    public function testTokenHashAndDerive(): void
    {
        $token = new Token('clave');
        $plain = Token::random();
        self::assertGreaterThanOrEqual(40, strlen($plain));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $plain);
        self::assertSame($token->hash($plain), $token->hash($plain));
        self::assertSame(64, strlen($token->hash($plain)));
        self::assertSame($token->derive('share', 'abc'), $token->derive('share', 'abc'));
        self::assertNotSame($token->derive('share', 'abc'), (new Token('otra'))->derive('share', 'abc'));
    }

    public function testTokenRequiresKey(): void
    {
        $this->expectException(\RuntimeException::class);
        new Token('');
    }

    public function testParseExtensions(): void
    {
        self::assertSame(['exe', 'bat', 'js'], SettingsService::parseExtensions('.EXE, bat;js  exe'));
        self::assertSame([], SettingsService::parseExtensions(''));
        self::assertSame(['ok'], SettingsService::parseExtensions('ok, ../mal, <x>'));
    }

    public function testPresentKindAndIso(): void
    {
        self::assertSame('sheet', Present::kind('xlsx'));
        self::assertSame('file', Present::kind('xyz'));
        self::assertSame('2026-09-28T10:00:00Z', Present::iso('2026-09-28 10:00:00'));
        self::assertNull(Present::iso(null));
    }

    public function testHtmlToText(): void
    {
        $text = MailService::htmlToText('<p>Hola <strong>Ana</strong></p><a href="https://x.co/a?b=1&amp;c=2">Entrar</a>');
        self::assertStringContainsString('Hola Ana', $text);
        self::assertStringContainsString('Entrar: https://x.co/a?b=1&c=2', $text);
    }
}
