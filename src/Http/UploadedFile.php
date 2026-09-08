<?php

declare(strict_types=1);

namespace FachDock\Http;

use RuntimeException;

final class UploadedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $temporaryPath,
        public readonly int $size,
        public readonly int $error,
    ) {
    }

    public function assertValid(int $maximumBytes = 5_000_000): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Die CSV-Datei konnte nicht hochgeladen werden.');
        }
        if ($this->size < 1 || $this->size > $maximumBytes) {
            throw new RuntimeException('Die CSV-Datei ist leer oder überschreitet die zulässige Größe.');
        }
        if (!is_uploaded_file($this->temporaryPath) && PHP_SAPI !== 'cli') {
            throw new RuntimeException('Die hochgeladene Datei ist ungültig.');
        }
    }
}
