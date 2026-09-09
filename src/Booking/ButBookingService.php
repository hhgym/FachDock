<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DateTimeImmutable;
use DomainException;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Mail\BookingNotificationService;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use RuntimeException;
use Throwable;

final class ButBookingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BookingService $bookings,
        private readonly FeeCalculator $fees = new FeeCalculator(),
        private readonly int $rejectionPaymentDays = 14,
        private readonly ?BookingNotificationService $notifications = null,
    ) {
    }

    public function submit(AuthenticatedParent $parent, int $reservationId): int
    {
        if ($reservationId < 1) {
            throw new DomainException('Die Reservierung ist ungültig.');
        }

        $context = $this->reservationForParent($parent, $reservationId);
        $period = SchoolYearPeriod::fromStartYear((int) substr($context['starts_on'], 0, 4));
        $bookingDate = new DateTimeImmutable($context['current_date']);
        $proration = $bookingDate < $period->startsOn
            ? ['months' => 12, 'charged_cents' => $context['annual_fee_cents']]
            : $this->fees->prorate($context['annual_fee_cents'], $bookingDate, $period);

        $bookingId = $this->bookings->convertReservation(
            $reservationId,
            new BookingCreationData(
                BookingStatus::ExemptionReview,
                'parent',
                $parent->id,
                $context['annual_fee_cents'],
                0,
                $proration['months'],
                'but_pending',
                null,
                'initial_booking',
            ),
        );
        $this->notifications?->butSubmitted($bookingId);

        return $bookingId;
    }

    /**
     * @return list<array{
     *     booking_id: int,
     *     student_id: int,
     *     student_name: string,
     *     class_name: string,
     *     school_year_label: string,
     *     locker_short_name: string,
     *     parent_email: string|null,
     *     annual_fee_cents: int,
     *     proration_months: int,
     *     fallback_fee_cents: int,
     *     created_at: string
     * }>
     */
    public function pendingReviews(): array
    {
        $statement = $this->pdo->query(
            'SELECT b.id AS booking_id, b.student_id, s.first_name, s.last_name, s.class_name, '
            . 'sy.label AS school_year_label, l.short_name AS locker_short_name, pc.email AS parent_email, '
            . 'b.annual_fee_cents, b.proration_months, b.created_at '
            . 'FROM bookings b '
            . 'INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . "LEFT JOIN parent_contacts pc ON b.initiated_by_type = 'parent' AND pc.id = b.initiated_by_id "
            . "WHERE b.status = 'exemption_review' AND b.fee_exemption_type = 'but_pending' "
            . 'ORDER BY b.created_at, b.id'
        );
        if ($statement === false) {
            throw new RuntimeException('Die offenen BuT-Prüfungen konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $annualFee = (int) ($row['annual_fee_cents'] ?? 0);
            $months = (int) ($row['proration_months'] ?? 12);
            $result[] = [
                'booking_id' => (int) $row['booking_id'],
                'student_id' => (int) $row['student_id'],
                'student_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'class_name' => (string) $row['class_name'],
                'school_year_label' => (string) $row['school_year_label'],
                'locker_short_name' => (string) $row['locker_short_name'],
                'parent_email' => $row['parent_email'] !== null ? (string) $row['parent_email'] : null,
                'annual_fee_cents' => $annualFee,
                'proration_months' => $months,
                'fallback_fee_cents' => $this->feeFromSnapshot($annualFee, $months),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $result;
    }

    public function approve(AuthenticatedStaff $staff, int $bookingId, string $note): void
    {
        $this->review($staff, $bookingId, true, $note);
        $this->notifications?->butApproved($bookingId);
    }

    public function reject(AuthenticatedStaff $staff, int $bookingId, string $note): void
    {
        $this->review($staff, $bookingId, false, $note);
        $this->notifications?->butRejected($bookingId);
    }

    /**
     * @return array{
     *     booking_id: int,
     *     status: string,
     *     school_year_label: string,
     *     locker_short_name: string,
     *     charged_fee_cents: int,
     *     payment_due_at: string|null,
     *     exemption_review_note: string|null
     * }
     */
    public function bookingForParent(AuthenticatedParent $parent, int $bookingId): array
    {
        if ($bookingId < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT b.id AS booking_id, b.status, sy.label AS school_year_label, l.short_name AS locker_short_name, '
            . 'b.charged_fee_cents, b.payment_due_at, b.exemption_review_note '
            . 'FROM bookings b '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'WHERE b.id = :booking_id AND psl.parent_contact_id = :parent_contact_id LIMIT 1'
        );
        $statement->execute([
            'booking_id' => $bookingId,
            'parent_contact_id' => $parent->id,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Diese Buchung ist Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'booking_id' => (int) $row['booking_id'],
            'status' => (string) $row['status'],
            'school_year_label' => (string) $row['school_year_label'],
            'locker_short_name' => (string) $row['locker_short_name'],
            'charged_fee_cents' => (int) ($row['charged_fee_cents'] ?? 0),
            'payment_due_at' => $row['payment_due_at'] !== null ? (string) $row['payment_due_at'] : null,
            'exemption_review_note' => $row['exemption_review_note'] !== null
                ? (string) $row['exemption_review_note']
                : null,
        ];
    }

    /**
     * @return array{annual_fee_cents: int, starts_on: string, current_date: string}
     */
    private function reservationForParent(AuthenticatedParent $parent, int $reservationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sy.annual_fee_cents, sy.starts_on, CURRENT_DATE AS current_date '
            . 'FROM locker_reservations lr '
            . 'INNER JOIN reservation_slots rs ON rs.reservation_id = lr.id '
            . 'INNER JOIN school_years sy ON sy.id = lr.school_year_id '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = lr.student_id '
            . 'WHERE lr.id = :reservation_id AND psl.parent_contact_id = :parent_contact_id '
            . "AND lr.status = 'active' AND lr.expires_at > CURRENT_TIMESTAMP LIMIT 1"
        );
        $statement->execute([
            'reservation_id' => $reservationId,
            'parent_contact_id' => $parent->id,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Die Reservierung ist nicht mehr gültig oder Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
            'starts_on' => (string) $row['starts_on'],
            'current_date' => (string) $row['current_date'],
        ];
    }

    private function review(AuthenticatedStaff $staff, int $bookingId, bool $approved, string $note): void
    {
        $note = trim($note);
        if ($bookingId < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }
        if ($note === '') {
            throw new DomainException('Für die BuT-Entscheidung ist eine kurze Begründung erforderlich.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT annual_fee_cents, proration_months, status, fee_exemption_type FROM bookings '
                . 'WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $bookingId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new DomainException('Die Buchung existiert nicht.');
            }
            if ((string) $row['status'] !== BookingStatus::ExemptionReview->value
                || (string) $row['fee_exemption_type'] !== 'but_pending') {
                throw new DomainException('Für diese Buchung ist keine offene BuT-Prüfung vorhanden.');
            }

            $annualFee = (int) ($row['annual_fee_cents'] ?? 0);
            $months = (int) ($row['proration_months'] ?? 12);
            $params = [
                'id' => $bookingId,
                'staff_user_id' => $staff->id,
                'note' => mb_substr($note, 0, 4000),
            ];

            if ($approved) {
                $sql = "UPDATE bookings SET status = 'active', fee_exemption_type = 'but', charged_fee_cents = 0, "
                    . 'exemption_reviewed_at = CURRENT_TIMESTAMP, exemption_reviewed_by_staff_user_id = :staff_user_id, '
                    . 'exemption_review_note = :note, payment_due_at = NULL, updated_at = CURRENT_TIMESTAMP '
                    . 'WHERE id = :id';
            } else {
                $days = max(1, min(365, $this->rejectionPaymentDays));
                $sql = "UPDATE bookings SET status = 'payment_due', fee_exemption_type = 'but_rejected', "
                    . 'charged_fee_cents = :charged_fee_cents, '
                    . 'exemption_reviewed_at = CURRENT_TIMESTAMP, exemption_reviewed_by_staff_user_id = :staff_user_id, '
                    . 'exemption_review_note = :note, payment_due_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL '
                    . $days . ' DAY), updated_at = CURRENT_TIMESTAMP WHERE id = :id';
                $params['charged_fee_cents'] = $this->feeFromSnapshot($annualFee, $months);
            }

            $this->pdo->prepare($sql)->execute($params);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function feeFromSnapshot(int $annualFeeCents, int $months): int
    {
        if ($annualFeeCents < 0 || $months < 1 || $months > 12) {
            throw new RuntimeException('Der Gebühren-Snapshot der Buchung ist ungültig.');
        }

        return intdiv(($annualFeeCents * $months) + 6, 12);
    }
}
