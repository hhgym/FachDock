<?php

declare(strict_types=1);

namespace FachDock\Location;

use InvalidArgumentException;

final class LockerNaming
{
    public static function shortName(string $groupCode, int $corpusPosition, int $lockerPosition): string
    {
        self::assertPosition($corpusPosition, 'Corpus');
        self::assertPosition($lockerPosition, 'Locker');
        $groupCode = self::requiredCode($groupCode, 'Cabinet group');

        return sprintf('%s-%02d-%d', $groupCode, $corpusPosition, $lockerPosition);
    }

    public static function longName(
        string $floorCode,
        string $areaCode,
        string $groupCode,
        int $corpusPosition,
        int $lockerPosition,
    ): string {
        return sprintf(
            '%s-%s-%s',
            self::requiredCode($floorCode, 'Floor'),
            self::requiredCode($areaCode, 'Area'),
            self::shortName($groupCode, $corpusPosition, $lockerPosition),
        );
    }

    private static function assertPosition(int $position, string $label): void
    {
        if ($position < 1) {
            throw new InvalidArgumentException($label . ' position must be at least 1.');
        }
    }

    private static function requiredCode(string $code, string $label): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException($label . ' code must not be empty.');
        }

        return $code;
    }
}
