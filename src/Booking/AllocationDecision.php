<?php

declare(strict_types=1);

namespace FachDock\Booking;

final readonly class AllocationDecision
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public bool $allowed,
        public int $score,
        public array $snapshot,
        public ?string $reason = null,
    ) {
    }
}
