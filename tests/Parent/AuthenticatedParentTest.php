<?php

declare(strict_types=1);

namespace FachDock\Tests\Parent;

use FachDock\Parent\AuthenticatedParent;
use PHPUnit\Framework\TestCase;

final class AuthenticatedParentTest extends TestCase
{
    public function testUsesNameWhenAvailable(): void
    {
        $parent = new AuthenticatedParent(1, 'parent@example.test', 'Erika', 'Muster', 2);

        self::assertSame('Erika Muster', $parent->displayName());
    }

    public function testFallsBackToEmailWithoutName(): void
    {
        $parent = new AuthenticatedParent(1, 'parent@example.test', null, null, 2);

        self::assertSame('parent@example.test', $parent->displayName());
    }
}
