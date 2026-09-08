<?php

declare(strict_types=1);

namespace FachDock\Student;

final class AccessCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        $groups = [];
        for ($group = 0; $group < 3; $group++) {
            $part = '';
            for ($index = 0; $index < 4; $index++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $part;
        }

        return implode('-', $groups);
    }

    public function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }

    public function hash(string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }

    public function verify(string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($code));
    }
}
