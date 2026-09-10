<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class MailConfigurationTemplateTest extends TestCase
{
    public function testMailSettingsExplainWorkerLimitAndImmediateReserve(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/config-mail.php');
        self::assertIsString($template);

        self::assertStringContainsString('php bin/fachdock mail:work', $template);
        self::assertStringContainsString('empfohlen einmal pro Minute', $template);
        self::assertStringContainsString('Letzter erfolgreicher Mail-Worker-Lauf', $template);
        self::assertStringContainsString('name="immediate_reserve_per_hour"', $template);
        self::assertStringContainsString('rollierenden 60-Minuten-Limit', $template);
        self::assertStringContainsString('einschließlich Sofortmails', $template);
        self::assertStringContainsString('Dies ist kein SMTP-Verbindungs-Timeout', $template);
    }

    public function testParentMagicLinksAreMarkedAndTriggeredForImmediateDelivery(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/src/Parent/ParentPortalAccessService.php');
        self::assertIsString($service);

        self::assertStringContainsString('MailWorker::IMMEDIATE_PRIORITY_MAX', $service);
        self::assertStringContainsString('->runImmediate($queueId)', $service);
        self::assertStringContainsString('new MailWorkerFactory($config)', $service);
        self::assertStringContainsString("'parent_login'", $service);
        self::assertStringContainsString("'parent_verify_email'", $service);
    }
}
