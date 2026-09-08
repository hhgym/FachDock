<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use FachDock\Booking\AllocationRuleKind;
use PHPUnit\Framework\TestCase;

final class AllocationRuleKindTest extends TestCase
{
    public function testKindsExposeStableValuesAndLabels(): void
    {
        self::assertSame('hard_allow', AllocationRuleKind::HardAllow->value);
        self::assertSame('Verbindlich erlaubt', AllocationRuleKind::HardAllow->label());
        self::assertSame('hard_deny', AllocationRuleKind::HardDeny->value);
        self::assertSame('Verbindlich ausgeschlossen', AllocationRuleKind::HardDeny->label());
        self::assertSame('soft_prefer', AllocationRuleKind::SoftPrefer->value);
        self::assertSame('Bevorzugt', AllocationRuleKind::SoftPrefer->label());
    }
}
