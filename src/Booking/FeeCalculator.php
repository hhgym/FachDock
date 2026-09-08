<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DateTimeImmutable;
use DomainException;

final class FeeCalculator
{
    /** @return array{months: int, charged_cents: int} */
    public function prorate(int $annualFeeCents, DateTimeImmutable $bookingDate, SchoolYearPeriod $schoolYear): array
    {
        if ($annualFeeCents < 0) {
            throw new DomainException('Der Jahresbeitrag darf nicht negativ sein.');
        }
        if (!$schoolYear->contains($bookingDate)) {
            throw new DomainException('Das Buchungsdatum liegt außerhalb des Schuljahres.');
        }

        $month = (int) $bookingDate->format('n');
        $schoolMonthIndex = $month >= 8 ? $month - 8 : $month + 4;
        $months = 12 - $schoolMonthIndex;
        $chargedCents = intdiv(($annualFeeCents * $months) + 6, 12);

        return [
            'months' => $months,
            'charged_cents' => $chargedCents,
        ];
    }
}
