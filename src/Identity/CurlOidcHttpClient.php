<?php

declare(strict_types=1);

namespace FachDock\Identity;

use JsonException;
use RuntimeException;

final class CurlOidcHttpClient implements OidcHttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function getJson(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->request('POST', $url, http_build_query($fields, '', '&', PHP_QUERY_RFC3986), $headers + [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
    }

    /** @param array<string, string> $headers
     *  @return array<string, mixed>
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        if ($method === '') {
            throw new RuntimeException('OIDC-HTTP-Methode darf nicht leer sein.');
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('OIDC-Endpunkte müssen HTTPS verwenden.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('OIDC-Verbindung konnte nicht initialisiert werden.');
        }
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($response)) {
            throw new RuntimeException('OIDC-Verbindung fehlgeschlagen' . ($error !== '' ? ': ' . $error : '.'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('OIDC-Endpunkt antwortete mit HTTP ' . $status . '.');
        }

        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OIDC-Endpunkt lieferte ungültiges JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('OIDC-Endpunkt lieferte kein JSON-Objekt.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
