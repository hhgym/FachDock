<?php

declare(strict_types=1);

namespace FachDock\Update;

use JsonException;
use RuntimeException;

final class UpdateDiscoveryService
{
    public function __construct(
        private readonly GitHubReleaseClient $downloads,
        private readonly string $repository = 'hhgym/FachDock',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function latest(UpdateChannel $channel): UpdateInfo
    {
        return match ($channel) {
            UpdateChannel::Stable => $this->downloads->latestStable(),
            UpdateChannel::ReleaseCandidate => $this->latestReleaseCandidate(),
            UpdateChannel::Develop => $this->latestDevelop(),
        };
    }

    private function latestReleaseCandidate(): UpdateInfo
    {
        $releases = $this->requestJson('https://api.github.com/repos/' . $this->repository . '/releases?per_page=50');
        $selected = null;
        $selectedVersion = null;

        foreach ($releases as $release) {
            if (!is_array($release) || ($release['draft'] ?? false) === true) {
                continue;
            }

            $tag = $release['tag_name'] ?? null;
            if (!is_string($tag)
                || preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[1-9][0-9]*)?)$/', $tag, $matches) !== 1) {
                continue;
            }

            $version = $matches[1];
            $isRc = str_contains($version, '-rc.');
            if ($isRc !== (($release['prerelease'] ?? false) === true)) {
                continue;
            }

            if ($selectedVersion === null || version_compare($version, $selectedVersion, '>')) {
                $selected = $release;
                $selectedVersion = $version;
            }
        }

        if (!is_array($selected) || $selectedVersion === null) {
            throw new RuntimeException('GitHub liefert keinen gültigen stabilen Release oder Release Candidate.');
        }

        return $this->releaseInfo($selected, $selectedVersion);
    }

    private function latestDevelop(): UpdateInfo
    {
        $prefix = 'https://github.com/' . $this->repository . '/raw/refs/heads/develop-build/';
        $data = $this->decodeJson($this->downloads->text($prefix . 'manifest.json'));

        $version = $this->stringValue($data, 'version');
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-rc\.[1-9][0-9]*)?$/', $version) !== 1) {
            throw new RuntimeException('Das Develop-Manifest enthält keine gültige FachDock-Basisversion.');
        }

        $buildId = strtolower($this->stringValue($data, 'build_id'));
        if (preg_match('/^[a-f0-9]{40}$/', $buildId) !== 1) {
            throw new RuntimeException('Das Develop-Manifest enthält keine gültige Commit-ID.');
        }

        $zipUrl = $this->stringValue($data, 'zip_url');
        $checksumUrl = $this->stringValue($data, 'checksum_url');
        if (!str_starts_with($zipUrl, $prefix) || !str_starts_with($checksumUrl, $prefix)) {
            throw new RuntimeException('Das Develop-Manifest verweist auf eine unzulässige Download-Adresse.');
        }

        return new UpdateInfo(
            $version,
            'develop-' . substr($buildId, 0, 12),
            $zipUrl,
            $checksumUrl,
            'https://github.com/' . $this->repository . '/commit/' . $buildId,
            isset($data['built_at']) && is_string($data['built_at']) ? $data['built_at'] : null,
            UpdateChannel::Develop,
            $buildId,
        );
    }

    /** @param array<mixed> $data */
    private function releaseInfo(array $data, string $version): UpdateInfo
    {
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
            $this->stringValue($data, 'tag_name'),
            $zipUrl,
            $checksumUrl,
            $this->stringValue($data, 'html_url'),
            isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : null,
            UpdateChannel::ReleaseCandidate,
        );
    }

    /** @return array<mixed> */
    private function requestJson(string $url): array
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

        return $this->decodeJson($response);
    }

    /** @return array<mixed> */
    private function decodeJson(string $content): array
    {
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

    /** @param array<mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('GitHub-Antwort enthält kein gültiges Feld: ' . $key);
        }

        return $value;
    }
}
