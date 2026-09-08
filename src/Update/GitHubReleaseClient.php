<?php

declare(strict_types=1);

namespace FachDock\Update;

use JsonException;
use RuntimeException;

final class GitHubReleaseClient
{
    public function __construct(
        private readonly string $repository = 'hhgym/FachDock',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function latestStable(): UpdateInfo
    {
        $data = $this->requestJson('https://api.github.com/repos/' . $this->repository . '/releases/latest');
        $tag = $this->stringValue($data, 'tag_name');
        if (preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)$/', $tag, $matches) !== 1) {
            throw new RuntimeException('GitHub liefert keinen gültigen stabilen FachDock-Release.');
        }
        if (($data['draft'] ?? false) === true || ($data['prerelease'] ?? false) === true) {
            throw new RuntimeException('Der gefundene GitHub-Release ist nicht stabil.');
        }

        $version = $matches[1];
        $assets = $data['assets'] ?? null;
        if (!is_array($assets)) {
            throw new RuntimeException('Der GitHub-Release enthält keine Update-Dateien.');
        }

        $zipName = 'FachDock-' . $version . '.zip';
        $checksumName = $zipName . '.sha256';
        $zipUrl = null;
        $checksumUrl = null;

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = $asset['name'] ?? null;
            $url = $asset['browser_download_url'] ?? null;
            if (!is_string($name) || !is_string($url)) {
                continue;
            }
            if ($name === $zipName) {
                $zipUrl = $url;
            } elseif ($name === $checksumName) {
                $checksumUrl = $url;
            }
        }

        if ($zipUrl === null || $checksumUrl === null) {
            throw new RuntimeException('Release-ZIP oder SHA-256-Prüfsumme fehlt im GitHub-Release.');
        }

        return new UpdateInfo(
            $version,
            $tag,
            $zipUrl,
            $checksumUrl,
            $this->stringValue($data, 'html_url'),
            isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function requestJson(string $url): array
    {
        $content = $this->request($url);
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub-Antwort konnte nicht gelesen werden.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub-Antwort hat ein unerwartetes Format.');
        }

        return $decoded;
    }

    public function download(string $url, string $target): void
    {
        $this->assertDownloadUrl($url);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Update-Verzeichnis konnte nicht angelegt werden.');
        }

        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Update-Datei konnte nicht angelegt werden.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Download konnte nicht initialisiert werden.');
        }
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'FachDock Update Client',
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
            CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok !== true) {
            @unlink($target);
            throw new RuntimeException('Update-Datei konnte nicht geladen werden: ' . $error);
        }
    }

    public function text(string $url): string
    {
        $this->assertDownloadUrl($url);

        return $this->request($url);
    }

    private function request(string $url): string
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('GitHub-Anfrage konnte nicht initialisiert werden.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => 'FachDock Update Client',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_FAILONERROR => true,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($response)) {
            throw new RuntimeException('GitHub-Anfrage ist fehlgeschlagen: ' . $error);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('GitHub-Release enthält kein gültiges Feld: ' . $key);
        }

        return $value;
    }

    private function assertDownloadUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !in_array($host, ['github.com', 'objects.githubusercontent.com'], true)) {
            throw new RuntimeException('Unzulässige Download-Adresse für das Update.');
        }
    }
}
