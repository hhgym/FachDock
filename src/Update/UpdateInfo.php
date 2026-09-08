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
    ) {
    }

    public function isNewerThan(string $currentVersion): bool
    {
        return version_compare($this->version, $currentVersion, '>');
    }
}
