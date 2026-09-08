<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DomainException;
use FachDock\Booking\LockerRecommendationRanker;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class LockerRecommendationRankerTest extends TestCase
{
    public function testHigherScoresAlwaysComeFirstAndTiesStayWithinTheirGroup(): void
    {
        $ranker = new LockerRecommendationRanker(new Randomizer(new Mt19937(1234)));
        $candidates = [
            ['short_name' => 'A-01-1', 'score' => 5],
            ['short_name' => 'A-01-2', 'score' => 10],
            ['short_name' => 'A-01-3', 'score' => 10],
            ['short_name' => 'A-01-4', 'score' => 0],
        ];

        $result = $ranker->recommend($candidates, 3);

        self::assertCount(3, $result);
        self::assertSame(10, $result[0]['score']);
        self::assertSame(10, $result[1]['score']);
        self::assertSame(5, $result[2]['score']);
        self::assertEqualsCanonicalizing(['A-01-2', 'A-01-3'], [
            $result[0]['short_name'],
            $result[1]['short_name'],
        ]);
    }

    public function testLimitIsValidated(): void
    {
        $ranker = new LockerRecommendationRanker();

        $this->expectException(DomainException::class);
        $ranker->recommend([], 0);
    }
}
