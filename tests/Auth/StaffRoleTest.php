<?php

declare(strict_types=1);

namespace FachDock\Tests\Auth;

use FachDock\Auth\StaffRole;
use PHPUnit\Framework\TestCase;

final class StaffRoleTest extends TestCase
{
    public function testLabels(): void
    {
        self::assertSame('Administrator', StaffRole::Administrator->label());
        self::assertSame('Schließfachverwaltung', StaffRole::LockerManager->label());
    }
}
