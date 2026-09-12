<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use FachDock\Booking\BookingReceiptPdf;
use PHPUnit\Framework\TestCase;

final class BookingReceiptPdfTest extends TestCase
{
    public function testRendersDownloadablePdfWithBookingText(): void
    {
        $pdf = (new BookingReceiptPdf())->render(
            'Buchungsbestätigung und Zahlungsbeleg',
            ['Schließfach: A-07-2', 'Zahlungsstatus: bezahlt'],
        );

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('A-07-2', $pdf);
        self::assertStringContainsString('startxref', $pdf);
        self::assertStringEndsWith('%%EOF', $pdf);
    }
}
