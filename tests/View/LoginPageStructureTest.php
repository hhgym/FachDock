<?php

declare(strict_types=1);

namespace FachDock\Tests\View;

use PHPUnit\Framework\TestCase;

final class LoginPageStructureTest extends TestCase
{
    public function testParentAndStudentAccessPrecedeAdministrativeLogin(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/login.php');

        $student = strpos($template, 'Schülerzugang');
        $parent = strpos($template, 'Elternzugang');
        $admin = strpos($template, 'Administration und Schließfachverwaltung');

        self::assertIsInt($student);
        self::assertIsInt($parent);
        self::assertIsInt($admin);
        self::assertLessThan($admin, $student);
        self::assertLessThan($admin, $parent);
        self::assertStringContainsString('/sso/login?area=student', $template);
        self::assertStringContainsString('/parent/login', $template);
    }

    public function testOpenIdConnectLoginLabelIsConfigurable(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Identity/OidcConfiguration.php');
        $adminTemplate = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/config-oidc.php');
        $loginTemplate = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/login.php');

        self::assertStringContainsString('public function loginLabel(): string', $configuration);
        self::assertStringContainsString('name="login_label"', $adminTemplate);
        self::assertStringContainsString('$oidc->loginLabel()', $loginTemplate);
    }

    public function testVisibleOpenIdConnectTemplatesAreProviderNeutral(): void
    {
        foreach (['login.php', 'config-oidc.php', 'teacher-portal.php', 'oidc-error.php'] as $template) {
            $content = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
            self::assertStringNotContainsString('IServ', $content, $template . ' must not hard-code a provider name.');
        }
    }
}
