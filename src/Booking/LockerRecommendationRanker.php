<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use Random\Randomizer;

final class LockerRecommendationRanker
{
    public function __construct(private readonly Randomizer $randomizer = new Randomizer())
    {
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    public function recommend(array $candidates, int $limit = 3): array
    {
        if ($limit < 1 || $limit > 25) {
            throw new DomainException('Die Anzahl der Empfehlungen muss zwischen 1 und 25 liegen.');
        }

        $groups = [];
        foreach ($candidates as $candidate) {
            $score = (int) ($candidate['score'] ?? 0);
            $groups[$score][] = $candidate;
        }
        krsort($groups, SORT_NUMERIC);

        $ranked = [];
        foreach ($groups as $group) {
            foreach ($this->randomizer->shuffleArray($group) as $candidate) {
                $ranked[] = $candidate;
                if (count($ranked) >= $limit) {
                    return $ranked;
                }
            }
        }

        return $ranked;
    }
}
