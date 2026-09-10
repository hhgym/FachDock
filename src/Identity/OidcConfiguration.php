<?php

declare(strict_types=1);

namespace FachDock\Identity;

use FachDock\Config\Config;
use RuntimeException;

final class OidcConfiguration
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->get('oidc.enabled', false) === true;
    }

    public function issuer(): string
    {
        return rtrim(trim((string) $this->config->get('oidc.issuer', '')), '/');
    }

    public function clientId(): string
    {
        return trim((string) $this->config->get('oidc.client_id', ''));
    }

    public function clientSecret(): string
    {
        return (string) $this->config->get('oidc.client_secret', '');
    }

    public function callbackUrl(): string
    {
        $base = rtrim(trim((string) $this->config->get('app.base_url', '')), '/');

        return $base === '' ? '' : $base . '/sso/callback';
    }

    public function scopes(): string
    {
        $value = trim((string) $this->config->get(
            'oidc.scopes',
            'openid profile email iserv:uuid iserv:groups iserv:roles',
        ));

        return $value !== '' ? $value : 'openid profile email';
    }

    public function studentAutoMatch(): string
    {
        $value = (string) $this->config->get('oidc.student_auto_match', 'email');

        return in_array($value, ['none', 'email', 'username_to_matrikelnummer'], true) ? $value : 'email';
    }

    /** @return list<string> */
    public function teacherRoleNames(): array
    {
        $raw = (string) $this->config->get('oidc.teacher_role_names', 'Lehrer,Lehrkräfte');
        $result = [];
        foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $role) {
            $role = mb_strtolower(trim($role));
            if ($role !== '' && !in_array($role, $result, true)) {
                $result[] = $role;
            }
        }

        return $result;
    }

    public function sessionLifetimeMinutes(): int
    {
        $value = $this->config->get('oidc.session_lifetime_minutes', 480);

        return is_numeric($value) ? min(10080, max(15, (int) $value)) : 480;
    }

    public function assertReady(): void
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Die IServ-Anmeldung ist nicht aktiviert.');
        }
        $issuer = $this->issuer();
        if (!$this->validHttpsUrl($issuer)) {
            throw new RuntimeException('Für IServ muss eine gültige HTTPS-Issuer-URL konfiguriert sein.');
        }
        if ($this->clientId() === '') {
            throw new RuntimeException('Die OIDC-Client-ID fehlt.');
        }
        if ($this->clientSecret() === '') {
            throw new RuntimeException('Das OIDC-Client-Geheimnis fehlt.');
        }
        if (!$this->validHttpsUrl($this->callbackUrl())) {
            throw new RuntimeException('Die öffentliche FachDock-Basis-URL muss für OIDC als HTTPS-URL konfiguriert sein.');
        }
    }

    public function ready(): bool
    {
        try {
            $this->assertReady();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function validHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
