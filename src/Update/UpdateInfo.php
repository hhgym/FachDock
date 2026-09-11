<?php

declare(strict_types=1);

namespace FachDock\Update;

final readonly class UpdateInfo
{
    public function __construct(
        public string $version,
        public string $tag,
        public string $zipUrl,
        public string $checksumUrl,
        public string $releaseUrl,
        public ?string $publishedAt,
        public UpdateChannel $channel = UpdateChannel::Stable,
        public ?string $buildId = null,
    ) {
    }

    public function isNewerThan(string $currentVersion): bool
    {
        return version_compare($this->version, $currentVersion, '>');
    }

    public function isAvailableFor(string $currentVersion, ?string $currentDevelopBuildId = null): bool
    {
        if ($this->channel === UpdateChannel::Develop) {
            return $this->buildId !== null && $this->buildId !== $currentDevelopBuildId;
        }

        return $this->isNewerThan($currentVersion);
    }

    public function identity(): string
    {
        if ($this->channel === UpdateChannel::Develop && $this->buildId !== null) {
            return $this->buildId;
        }

        return $this->tag;
    }

    public function displayVersion(): string
    {
        if ($this->channel === UpdateChannel::Develop && $this->buildId !== null) {
            return $this->version . ' · Develop ' . substr($this->buildId, 0, 12);
        }

        return $this->version;
    }
}
