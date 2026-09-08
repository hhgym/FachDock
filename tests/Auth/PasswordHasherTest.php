<?php

declare(strict_types=1);

namespace FachDock\Tests\Auth;

use FachDock\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testHashCanBeVerified(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('a sufficiently long password');

        self::assertTrue($hasher->verify('a sufficiently long password', $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
    }
}
