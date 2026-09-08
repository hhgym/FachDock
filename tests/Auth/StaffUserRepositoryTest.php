<?php

declare(strict_types=1);

namespace FachDock\Tests\Auth;

use FachDock\Auth\StaffUserRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StaffUserRepositoryTest extends TestCase
{
    public function testIdentifierLookupUsesUniqueNamedPlaceholders(): void
    {
        $reflection = new ReflectionClass(StaffUserRepository::class);
        $constant = $reflection->getReflectionConstant('FIND_BY_IDENTIFIER_SQL');

        self::assertNotFalse($constant);
        $sql = $constant->getValue();
        self::assertIsString($sql);

        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $sql, $matches);
        $placeholders = $matches[0];

        self::assertContains(':username_identifier', $placeholders);
        self::assertContains(':email_identifier', $placeholders);
        self::assertSame($placeholders, array_values(array_unique($placeholders)));
    }
}
