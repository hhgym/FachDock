<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class ParentBookingSummaryTemplateTest extends TestCase
{
    public function testSummaryProvidesClearBookingCompletionStep(): void
    {
        $root = dirname(__DIR__, 2);
        $template = (string) file_get_contents($root . '/templates/parent-booking-summary.php');
        $controller = (string) file_get_contents($root . '/src/Booking/ParentBookingController.php');

        self::assertStringContainsString('<h1>Buchung abschließen</h1>', $template);
        self::assertStringContainsString('Zusammenfassung', $template);
        self::assertStringContainsString('Online bezahlen', $template);
        self::assertStringContainsString('Buchung abschließen ·', $template);
        self::assertStringContainsString('BuT-Befreiung beantragen', $template);
        self::assertStringContainsString('Anderes Schließfach auswählen', $template);
        self::assertStringContainsString('/parent/booking/map?student_id=', $template);
        self::assertStringContainsString('Heute fällig', $template);
        self::assertStringContainsString('von 12 Monaten', $template);
        self::assertStringContainsString('Zahlung fortsetzen ·', $template);
        self::assertStringContainsString('Es wird kein neuer Zahlungsvorgang angelegt.', $template);

        self::assertStringContainsString("\$router->get('/parent/booking/summary'", $controller);
        self::assertStringContainsString('return Response::redirect($this->summaryUrl($studentId, $schoolYearId));', $controller);
        self::assertStringContainsString("\$this->queryString(\$request, 'payment_cancelled') === '1'", $controller);
        self::assertStringContainsString("'feeQuote' => \$this->feeQuote(\$schoolYear)", $controller);
    }
}
