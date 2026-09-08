<?php

declare(strict_types=1);

namespace FachDock\Tests\Parent;

use DomainException;
use FachDock\Parent\ParentMagicLinkToken;
use PHPUnit\Framework\TestCase;

final class ParentMagicLinkTokenTest extends TestCase
{
    public function testGeneratedTokensUseUrlSafeFixedLengthFormat(): void
    {
        $tokens = new ParentMagicLinkToken();
        $token = $tokens->generate();

        self::assertSame(43, strlen($token));
        self::assertTrue($tokens->isValidFormat($token));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function testGeneratedTokensAreNotRepeated(): void
    {
        $tokens = new ParentMagicLinkToken();

        self::assertNotSame($tokens->generate(), $tokens->generate());
    }

    public function testHashIsStableAndDoesNotContainRawToken(): void
    {
        $tokens = new ParentMagicLinkToken();
        $token = $tokens->generate();
        $hash = $tokens->hash($token);

        self::assertSame(64, strlen($hash));
        self::assertSame($hash, $tokens->hash($token));
        self::assertNotSame($token, $hash);
    }

    public function testInvalidTokenCannotBeHashedForLookup(): void
    {
        $tokens = new ParentMagicLinkToken();

        $this->expectException(DomainException::class);
        $tokens->hash('too-short');
    }
}
