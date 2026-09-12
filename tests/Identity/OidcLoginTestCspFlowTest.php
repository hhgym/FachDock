<?php

declare(strict_types=1);

namespace FachDock\Tests\Identity;

use PHPUnit\Framework\TestCase;

final class OidcLoginTestCspFlowTest extends TestCase
{
    public function testLoginTestUsesSameOriginGetHopBeforeLeavingFachDock(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Identity/OidcAdminFrontController.php',
        );

        self::assertStringContainsString(
            "private const LOGIN_TEST_REDIRECT_KEY = 'oidc_admin_login_test_redirect';",
            $source,
        );
        self::assertStringContainsString(
            "Response::redirect('/admin/config/oidc/login-test?continue=1')",
            $source,
        );
        self::assertStringContainsString(
            "Response::redirect(self::consumeLoginTestRedirect())",
            $source,
        );
        self::assertStringNotContainsString(
            'return Response::redirect($loginTest->begin());',
            $source,
        );
    }
}
