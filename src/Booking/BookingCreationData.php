<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;

final readonly class BookingCreationData
{
    public function __construct(
        public BookingStatus $status,
        public string $initiatedByType,
        public ?int $initiatedById = null,
        public ?int $annualFeeCents = null,
        public ?int $chargedFeeCents = null,
        public ?int $prorationMonths = null,
        public ?string $feeExemptionType = null,
        public ?int $previousBookingId = null,
        public string $assignmentReason = 'initial_booking',
    ) {
        if (!in_array($status, [BookingStatus::Active, BookingStatus::ExemptionReview], true)) {
            throw new DomainException('Dieser Buchungsstatus kann nicht aus einer Reservierung erzeugt werden.');
        }
        if (trim($initiatedByType) === '') {
            throw new DomainException('Der Auslöser der Buchung muss angegeben werden.');
        }
        if ($annualFeeCents !== null && $annualFeeCents < 0) {
            throw new DomainException('Der Jahresbeitrag darf nicht negativ sein.');
        }
        if ($chargedFeeCents !== null && $chargedFeeCents < 0) {
            throw new DomainException('Der berechnete Beitrag darf nicht negativ sein.');
        }
        if ($prorationMonths !== null && ($prorationMonths < 1 || $prorationMonths > 12)) {
            throw new DomainException('Die anteiligen Monate müssen zwischen 1 und 12 liegen.');
        }
        if (trim($assignmentReason) === '') {
            throw new DomainException('Der Grund der Schließfachzuweisung muss angegeben werden.');
        }
    }
}
