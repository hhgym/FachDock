<?php

declare(strict_types=1);

namespace FachDock\Security;

final class SecurityHeaders
{
    /**
     * @param array<string, mixed>|null $server
     * @return array<string, string>
     */
    public static function forRequest(?array $server = null): array
    {
        $server ??= $_SERVER;
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; "
                . "frame-ancestors 'none'; form-action 'self'",
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if (self::isHttps($server)) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /** @param array<string, mixed> $server */
    private static function isHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }
        $forwardedParts = explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $forwarded = strtolower(trim($forwardedParts[0]));

        return $forwarded === 'https';
    }
}
