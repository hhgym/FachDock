<?php

declare(strict_types=1);

namespace FachDock\Identity;

use RuntimeException;

final class OidcLoginTestService
{
    private const FLOW_KEY = 'oidc_admin_login_test_flow';
    private const FLOW_LIFETIME_SECONDS = 600;

    public function __construct(
        private readonly OidcConfiguration $configuration,
        private readonly OidcHttpClient $http,
    ) {
    }

    public function pending(): bool
    {
        return is_array($_SESSION[self::FLOW_KEY] ?? null);
    }

    /** @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:string} */
    public function connectionCheck(): array
    {
        $this->configuration->assertConfigured();
        $metadata = $this->metadata();

        return [
            'issuer' => (string) $metadata['issuer'],
            'authorization_endpoint' => (string) $metadata['authorization_endpoint'],
            'token_endpoint' => (string) $metadata['token_endpoint'],
            'userinfo_endpoint' => (string) $metadata['userinfo_endpoint'],
        ];
    }

    public function begin(): string
    {
        $this->configuration->assertConfigured();
        $metadata = $this->metadata();
        $state = bin2hex(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(48));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));

        $_SESSION[self::FLOW_KEY] = [
            'state' => $state,
            'verifier' => $verifier,
            'started_at' => time(),
        ];
        session_regenerate_id(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->configuration->clientId(),
            'redirect_uri' => $this->configuration->callbackUrl(),
            'scope' => $this->configuration->scopes(),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return (string) $metadata['authorization_endpoint'] . '?' . $query;
    }

    /** @return array<string, mixed> */
    public function finish(string $code, string $state, string $providerError = ''): array
    {
        $this->configuration->assertConfigured();
        $flow = $_SESSION[self::FLOW_KEY] ?? null;
        unset($_SESSION[self::FLOW_KEY]);

        if ($providerError !== '') {
            throw new RuntimeException('Der OpenID-Connect-Anbieter hat die Testanmeldung abgebrochen: ' . $providerError);
        }
        if (!is_array($flow)) {
            throw new RuntimeException('Die OpenID-Connect-Testanmeldung ist abgelaufen. Bitte erneut starten.');
        }

        $expectedState = $flow['state'] ?? null;
        $verifier = $flow['verifier'] ?? null;
        $startedAt = $flow['started_at'] ?? null;
        if (!is_string($expectedState) || !hash_equals($expectedState, $state)
            || !is_string($verifier) || !is_int($startedAt)
            || $startedAt < time() - self::FLOW_LIFETIME_SECONDS) {
            throw new RuntimeException('Die OpenID-Connect-Testanmeldung konnte nicht sicher bestätigt werden.');
        }
        if ($code === '') {
            throw new RuntimeException('Der OpenID-Connect-Anbieter hat keinen Autorisierungscode geliefert.');
        }

        $metadata = $this->metadata();
        $token = $this->http->postForm((string) $metadata['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->configuration->callbackUrl(),
            'client_id' => $this->configuration->clientId(),
            'code_verifier' => $verifier,
        ], [
            'Authorization' => 'Basic ' . base64_encode(
                $this->configuration->clientId() . ':' . $this->configuration->clientSecret(),
            ),
        ]);

        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Der OpenID-Connect-Anbieter hat kein gültiges Access Token geliefert.');
        }

        $claims = $this->http->getJson((string) $metadata['userinfo_endpoint'], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        $subject = $claims['sub'] ?? null;
        if (!is_string($subject) || trim($subject) === '') {
            throw new RuntimeException('Der OpenID-Connect-Anbieter hat bei der Testanmeldung keine Subject-ID geliefert.');
        }

        ksort($claims);

        return $claims;
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $issuer = $this->configuration->issuer();
        $metadata = $this->http->getJson($issuer . '/.well-known/openid-configuration');
        if (($metadata['issuer'] ?? null) !== $issuer) {
            throw new RuntimeException('Der OpenID-Connect-Issuer der Discovery-Antwort stimmt nicht mit der Konfiguration überein.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            $endpoint = $metadata[$key] ?? null;
            if (!is_string($endpoint) || !$this->sameOriginHttps($issuer, $endpoint)) {
                throw new RuntimeException('Die OpenID-Connect-Discovery enthält einen unzulässigen Endpunkt: ' . $key . '.');
            }
        }

        return $metadata;
    }

    private function sameOriginHttps(string $issuer, string $endpoint): bool
    {
        if (!str_starts_with(strtolower($endpoint), 'https://')) {
            return false;
        }

        return strtolower((string) parse_url($issuer, PHP_URL_HOST)) === strtolower((string) parse_url($endpoint, PHP_URL_HOST))
            && (parse_url($issuer, PHP_URL_PORT) ?: 443) === (parse_url($endpoint, PHP_URL_PORT) ?: 443);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
