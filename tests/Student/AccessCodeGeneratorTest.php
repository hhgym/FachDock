<?php

declare(strict_types=1);

namespace FachDock\Tests\Student;

use FachDock\Student\AccessCodeGenerator;
use PHPUnit\Framework\TestCase;

final class AccessCodeGeneratorTest extends TestCase
{
    public function testGeneratedCodeHasReadableFormatAndCanBeVerified(): void
    {
        $generator = new AccessCodeGenerator();
        $code = $generator->generate();

        self::assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}(?:-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}){2}$/', $code);
        self::assertTrue($generator->verify($code, $generator->hash($code)));
        self::assertTrue($generator->verify(strtolower(str_replace('-', ' ', $code)), $generator->hash($code)));
    }
}
