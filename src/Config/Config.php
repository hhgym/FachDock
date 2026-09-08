<?php

declare(strict_types=1);

namespace FachDock\Config;

use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $root): self
    {
        $defaultFile = $root . '/config/app.php';
        if (!is_file($defaultFile)) {
            throw new RuntimeException('Default configuration is missing.');
        }

        /** @var array<string, mixed> $values */
        $values = require $defaultFile;

        $localFile = $root . '/config/app.local.php';
        if (is_file($localFile)) {
            /** @var array<string, mixed> $local */
            $local = require $localFile;
            $values = array_replace_recursive($values, $local);
        }

        $secretsFile = $root . '/config/secrets.local.php';
        if (is_file($secretsFile)) {
            /** @var array<string, mixed> $secrets */
            $secrets = require $secretsFile;
            $values = array_replace_recursive($values, $secrets);
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
