<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Http\RequestHelper;
use App\Services\AccessService;
use App\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidationAndAccessTest extends TestCase
{
    public function testSplitEmails(): void
    {
        self::assertSame(
            ['ana@a.com', 'luis@b.org', 'x@y.co'],
            Validator::splitEmails("Ana@A.com, luis@b.org; <x@y.co>\nana@a.com"),
        );
    }

    public function testIsEmailAcceptsAnyDomain(): void
    {
        self::assertTrue(Validator::isEmail('persona@secretaria.gov.co'));
        self::assertTrue(Validator::isEmail('a.b+c@empresa.io'));
        self::assertFalse(Validator::isEmail('sin-arroba'));
        self::assertFalse(Validator::isEmail(str_repeat('a', 190) . '@x.co'));
    }

    public function testPasswordRules(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('al menos 8');
        Validator::make(['password' => 'corta'])->password('password')->validate();
    }

    public function testPasswordConfirmation(): void
    {
        $v = Validator::make(['password' => 'suficiente-larga', 'password_confirmation' => 'otra-distinta'])->password('password', 'password_confirmation');
        self::assertArrayHasKey('password_confirmation', $v->errors());
    }

    public function testValidatorCollectsFieldErrors(): void
    {
        try {
            Validator::make(['name' => '', 'email' => 'malo'])->required('name', 'El nombre')->email('email')->validate();
            self::fail('Debió lanzar excepción');
        } catch (ValidationException $e) {
            self::assertSame('El nombre es obligatorio.', $e->getMessage());
            self::assertArrayHasKey('email', $e->details()['fields']);
        }
    }

    public function testPermissionRanking(): void
    {
        self::assertTrue(AccessService::allows('owner', 'editor'));
        self::assertTrue(AccessService::allows('editor', 'viewer'));
        self::assertFalse(AccessService::allows('viewer', 'editor'));
        self::assertFalse(AccessService::allows(null, 'viewer'));
        self::assertFalse(AccessService::allows('desconocido', 'viewer'));
    }

    public function testViewerCapabilities(): void
    {
        $caps = AccessService::capabilities('viewer', true);
        self::assertTrue($caps['view']);
        self::assertFalse($caps['upload']);
        self::assertFalse($caps['deleteItems']);
        self::assertFalse($caps['manageMembers']);
    }

    public function testEditorCanRenameSubfoldersButNotRoot(): void
    {
        self::assertFalse(AccessService::capabilities('editor', true)['rename']);
        self::assertTrue(AccessService::capabilities('editor', false)['rename']);
        self::assertFalse(AccessService::capabilities('editor', true)['deleteFolder']);
        self::assertTrue(AccessService::capabilities('owner', true)['deleteFolder']);
    }

    public function testGrantablePermissions(): void
    {
        self::assertSame(['editor', 'viewer'], AccessService::grantable('owner'));
        self::assertSame(['viewer'], AccessService::grantable('editor'));
        self::assertSame([], AccessService::grantable('viewer'));
    }

    public function testSafeNextPreventsOpenRedirects(): void
    {
        self::assertSame('/folders/x', RequestHelper::safeNext('/folders/x'));
        self::assertSame('/', RequestHelper::safeNext('//evil.com'));
        self::assertSame('/', RequestHelper::safeNext('https://evil.com'));
        self::assertSame('/', RequestHelper::safeNext('/\\evil.com'));
        self::assertSame('/', RequestHelper::safeNext(null));
    }
}
