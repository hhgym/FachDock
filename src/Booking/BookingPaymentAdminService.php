<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use PDO;

final class BookingPaymentAdminService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, label: string, status: string}> */
    public function schoolYears(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, label, status FROM school_years ORDER BY starts_on DESC, id DESC'
        );
        $rows = $statement->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'status' => (string) $row['status'],
            ],
            is_array($rows) ? $rows : [],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bookings(?int $schoolYearId, ?string $status, string $query): array
    {
        $where = [];
        $parameters = [];
        if ($schoolYearId !== null) {
            $where[] = 'b.school_year_id = :school_year_id';
            $parameters['school_year_id'] = $schoolYearId;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'b.status = :status';
            $parameters['status'] = $status;
        }
        if ($query !== '') {
            $where[] = '(CAST(b.id AS CHAR) = :query_exact OR s.first_name LIKE :query_like '
                . 'OR s.last_name LIKE :query_like OR s.class_name LIKE :query_like '
                . 'OR s.matrikelnummer LIKE :query_like OR l.short_name LIKE :query_like)';
            $parameters['query_exact'] = $query;
            $parameters['query_like'] = '%' . $query . '%';
        }

        $sql = 'SELECT b.id, b.status, b.projected_grade, b.valid_from, b.valid_until, '
            . 'b.annual_fee_cents, b.charged_fee_cents, b.proration_months, b.fee_exemption_type, '
            . 'b.payment_due_at, b.created_at, s.id AS student_id, s.first_name, s.last_name, '
            . 's.class_name, s.matrikelnummer, sy.id AS school_year_id, sy.label AS school_year_label, '
            . 'l.id AS locker_id, l.short_name AS locker_short_name, '
            . 'p.id AS payment_id, p.status AS payment_status, p.provider AS payment_provider, '
            . 'p.amount_cents AS payment_amount_cents, p.currency AS payment_currency '
            . 'FROM bookings b '
            . 'INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN lockers l ON l.id = lo.locker_id '
            . 'LEFT JOIN payments p ON p.id = ('
            . 'SELECT p2.id FROM payments p2 WHERE p2.booking_id = b.id '
            . 'ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1)';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY b.created_at DESC, b.id DESC LIMIT 250';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function booking(int $bookingId): array
    {
        if ($bookingId < 1) {
            throw new DomainException('Die Buchung ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT b.*, s.first_name, s.last_name, s.class_name, s.matrikelnummer, s.email AS student_email, '
            . 'sy.label AS school_year_label, sy.starts_on AS school_year_starts_on, sy.ends_on AS school_year_ends_on, '
            . 'l.id AS locker_id, l.short_name AS locker_short_name, cg.code AS group_code, '
            . 'a.name AS area_name, f.name AS floor_name, bu.name AS building_name, '
            . 'reviewer.display_name AS exemption_reviewer_name '
            . 'FROM bookings b '
            . 'INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN lockers l ON l.id = lo.locker_id '
            . 'LEFT JOIN corpuses c ON c.id = l.corpus_id '
            . 'LEFT JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'LEFT JOIN areas a ON a.id = cg.area_id '
            . 'LEFT JOIN floors f ON f.id = a.floor_id '
            . 'LEFT JOIN buildings bu ON bu.id = f.building_id '
            . 'LEFT JOIN staff_users reviewer ON reviewer.id = b.exemption_reviewed_by_staff_user_id '
            . 'WHERE b.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $bookingId]);
        $booking = $statement->fetch();
        if (!is_array($booking)) {
            throw new DomainException('Die Buchung wurde nicht gefunden.');
        }

        $paymentStatement = $this->pdo->prepare(
            'SELECT p.id, p.status, p.provider, p.amount_cents, p.currency, p.annual_fee_cents, '
            . 'p.proration_months, p.stripe_checkout_session_id, p.stripe_payment_intent_id, '
            . 'p.failure_code, p.failure_message, p.created_at, p.updated_at, p.paid_at, p.failed_at, '
            . 'pc.email AS parent_email, pc.first_name AS parent_first_name, pc.last_name AS parent_last_name '
            . 'FROM payments p INNER JOIN parent_contacts pc ON pc.id = p.parent_contact_id '
            . 'WHERE p.booking_id = :booking_id ORDER BY p.created_at DESC, p.id DESC'
        );
        $paymentStatement->execute(['booking_id' => $bookingId]);
        $payments = $paymentStatement->fetchAll();

        $assignmentStatement = $this->pdo->prepare(
            'SELECT h.id, h.locker_id, h.starts_at, h.ends_at, h.reason, h.actor_type, h.actor_id, '
            . 'l.short_name AS locker_short_name '
            . 'FROM locker_assignment_history h INNER JOIN lockers l ON l.id = h.locker_id '
            . 'WHERE h.booking_id = :booking_id ORDER BY h.starts_at DESC, h.id DESC'
        );
        $assignmentStatement->execute(['booking_id' => $bookingId]);
        $assignments = $assignmentStatement->fetchAll();

        $booking['payments'] = is_array($payments) ? array_values(array_filter($payments, 'is_array')) : [];
        $booking['assignments'] = is_array($assignments) ? array_values(array_filter($assignments, 'is_array')) : [];

        return $booking;
    }

    /** @return list<array<string, mixed>> */
    public function payments(?int $schoolYearId, ?string $status, string $query): array
    {
        $where = [];
        $parameters = [];
        if ($schoolYearId !== null) {
            $where[] = 'r.school_year_id = :school_year_id';
            $parameters['school_year_id'] = $schoolYearId;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'p.status = :status';
            $parameters['status'] = $status;
        }
        if ($query !== '') {
            $where[] = '(CAST(p.id AS CHAR) = :query_exact OR s.first_name LIKE :query_like '
                . 'OR s.last_name LIKE :query_like OR s.class_name LIKE :query_like '
                . 'OR pc.email LIKE :query_like OR l.short_name LIKE :query_like '
                . 'OR p.stripe_checkout_session_id LIKE :query_like OR p.stripe_payment_intent_id LIKE :query_like)';
            $parameters['query_exact'] = $query;
            $parameters['query_like'] = '%' . $query . '%';
        }

        $sql = 'SELECT p.id, p.status, p.provider, p.amount_cents, p.currency, p.booking_id, '
            . 'p.failure_code, p.failure_message, p.created_at, p.updated_at, p.paid_at, p.failed_at, '
            . 'r.id AS reservation_id, s.id AS student_id, s.first_name, s.last_name, s.class_name, '
            . 'sy.id AS school_year_id, sy.label AS school_year_label, l.short_name AS locker_short_name, '
            . 'pc.id AS parent_contact_id, pc.email AS parent_email, pc.first_name AS parent_first_name, '
            . 'pc.last_name AS parent_last_name, '
            . '(SELECT COUNT(*) FROM stripe_webhook_events swe '
            . "WHERE swe.payment_id = p.id AND swe.status <> 'processed') AS webhook_open_count "
            . 'FROM payments p '
            . 'INNER JOIN locker_reservations r ON r.id = p.reservation_id '
            . 'INNER JOIN students s ON s.id = r.student_id '
            . 'INNER JOIN school_years sy ON sy.id = r.school_year_id '
            . 'INNER JOIN lockers l ON l.id = r.locker_id '
            . 'INNER JOIN parent_contacts pc ON pc.id = p.parent_contact_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT 250';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function payment(int $paymentId): array
    {
        if ($paymentId < 1) {
            throw new DomainException('Die Zahlung ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT p.*, r.status AS reservation_status, r.expires_at AS reservation_expires_at, '
            . 'r.payment_grace_expires_at, s.id AS student_id, s.first_name, s.last_name, s.class_name, '
            . 's.matrikelnummer, sy.id AS school_year_id, sy.label AS school_year_label, '
            . 'l.id AS locker_id, l.short_name AS locker_short_name, '
            . 'pc.email AS parent_email, pc.first_name AS parent_first_name, pc.last_name AS parent_last_name '
            . 'FROM payments p '
            . 'INNER JOIN locker_reservations r ON r.id = p.reservation_id '
            . 'INNER JOIN students s ON s.id = r.student_id '
            . 'INNER JOIN school_years sy ON sy.id = r.school_year_id '
            . 'INNER JOIN lockers l ON l.id = r.locker_id '
            . 'INNER JOIN parent_contacts pc ON pc.id = p.parent_contact_id '
            . 'WHERE p.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $paymentId]);
        $payment = $statement->fetch();
        if (!is_array($payment)) {
            throw new DomainException('Die Zahlung wurde nicht gefunden.');
        }

        $eventStatement = $this->pdo->prepare(
            'SELECT stripe_event_id, event_type, status, received_at, processed_at, error_message '
            . 'FROM stripe_webhook_events WHERE payment_id = :payment_id '
            . 'ORDER BY received_at DESC, id DESC'
        );
        $eventStatement->execute(['payment_id' => $paymentId]);
        $events = $eventStatement->fetchAll();
        $payment['webhook_events'] = is_array($events) ? array_values(array_filter($events, 'is_array')) : [];

        return $payment;
    }

    /**
     * @return array{
     *     active_bookings: int,
     *     exemption_reviews: int,
     *     payment_due: int,
     *     payment_manual_review: int,
     *     payment_processing: int,
     *     active_reservations: int,
     *     webhook_open: int
     * }
     */
    public function dashboard(): array
    {
        return [
            'active_bookings' => $this->count("SELECT COUNT(*) FROM bookings WHERE status = 'active'"),
            'exemption_reviews' => $this->count("SELECT COUNT(*) FROM bookings WHERE status = 'exemption_review'"),
            'payment_due' => $this->count("SELECT COUNT(*) FROM bookings WHERE status = 'payment_due'"),
            'payment_manual_review' => $this->count("SELECT COUNT(*) FROM payments WHERE status = 'manual_review'"),
            'payment_processing' => $this->count("SELECT COUNT(*) FROM payments WHERE status = 'processing_paid'"),
            'active_reservations' => $this->count(
                "SELECT COUNT(*) FROM locker_reservations WHERE status IN ('active', 'payment_running')"
            ),
            'webhook_open' => $this->count(
                "SELECT COUNT(*) FROM stripe_webhook_events WHERE status <> 'processed'"
            ),
        ];
    }

    private function count(string $sql): int
    {
        $value = $this->pdo->query($sql)->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }
}
