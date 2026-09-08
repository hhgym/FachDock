<?php

declare(strict_types=1);

namespace FachDock\Tests\Mail;

use FachDock\Mail\MailRetrySchedule;
use PHPUnit\Framework\TestCase;

final class MailRetryScheduleTest extends TestCase
{
    public function testUsesConfiguredRetryWindows(): void
    {
        $schedule = new MailRetrySchedule([15, 60, 360]);

        self::assertSame(15, $schedule->delayAfterFailure(1));
        self::assertSame(60, $schedule->delayAfterFailure(2));
        self::assertSame(360, $schedule->delayAfterFailure(3));
        self::assertNull($schedule->delayAfterFailure(4));
    }
}
