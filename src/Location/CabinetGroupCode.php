<?php

declare(strict_types=1);

namespace FachDock\Location;

use InvalidArgumentException;

final class CabinetGroupCode
{
    public static function fromSequence(int $sequence): string
    {
        if ($sequence < 1) {
            throw new InvalidArgumentException('Cabinet group sequence must be at least 1.');
        }

        $code = '';
        while ($sequence > 0) {
            $sequence--;
            $code = chr(65 + ($sequence % 26)) . $code;
            $sequence = intdiv($sequence, 26);
        }

        return $code;
    }

    public static function next(string $current): string
    {
        $current = strtoupper(trim($current));
        if ($current === '' || preg_match('/^[A-Z]+$/', $current) !== 1) {
            throw new InvalidArgumentException('Cabinet group code must contain uppercase letters only.');
        }

        $sequence = 0;
        foreach (str_split($current) as $character) {
            $sequence = ($sequence * 26) + (ord($character) - 64);
        }

        return self::fromSequence($sequence + 1);
    }
}
