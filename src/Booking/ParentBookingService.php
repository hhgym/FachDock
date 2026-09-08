<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use RuntimeException;

final class ParentBookingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LockerRecommendationService $recommendations,
        private readonly ReservationService $reservations,
        private readonly LockerRecommendationRanker $ranker = new LockerRecommendationRanker(),
    ) {
    }

    /**
     * @return list<array{id: int, label: string, starts_on: string, ends_on: string, annual_fee_cents: int}>
     */
    public function bookableSchoolYears(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, label, starts_on, ends_on, annual_fee_cents FROM school_years '
            . "WHERE status <> 'closed' AND ends_on >= CURRENT_DATE "
            . 'AND new_booking_opens_on <= CURRENT_DATE ORDER BY starts_on'
        );
        if ($statement === false) {
            throw new RuntimeException('Die buchbaren Schuljahre konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'starts_on' => (string) $row['starts_on'],
                'ends_on' => (string) $row['ends_on'],
                'annual_fee_cents' => (int) $row['annual_fee_cents'],
            ];
        }

        return $result;
    }

    /**
     * @return array{id: int, first_name: string, last_name: string, class_name: string, grade: int}
     */
    public function linkedChild(AuthenticatedParent $parent, int $studentId): array
    {
        if ($studentId < 1) {
            throw new DomainException('Der Schüler ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT s.id, s.first_name, s.last_name, s.class_name, s.grade '
            . 'FROM parent_student_link_slots slot '
            . 'INNER JOIN students s ON s.id = slot.student_id '
            . 'WHERE slot.parent_contact_id = :parent_contact_id AND slot.student_id = :student_id '
            . 'AND s.active = 1 LIMIT 1'
        );
        $statement->execute([
            'parent_contact_id' => $parent->id,
            'student_id' => $studentId,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Dieser Schüler ist Ihrem Elternkonto nicht zugeordnet.');
        }

        return [
            'id' => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'class_name' => (string) $row['class_name'],
            'grade' => (int) $row['grade'],
        ];
    }

    /**
     * @return array{
     *     child: array{id: int, first_name: string, last_name: string, class_name: string, grade: int},
     *     school_year: array{id: int, label: string, starts_on: string, ends_on: string, annual_fee_cents: int},
     *     projected_grade: int,
     *     active_reservation: array<string, mixed>|null,
     *     recommended: list<array<string, mixed>>,
     *     available: list<array<string, mixed>>
     * }
     */
    public function selection(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        int $recommendationCount,
    ): array {
        $child = $this->linkedChild($parent, $studentId);
        $schoolYear = $this->bookableSchoolYear($schoolYearId);
        $projectedGrade = $this->recommendations->projectedGradeForStudent($studentId, $schoolYearId);
        $activeReservation = $this->reservations->activeForStudent($studentId, $schoolYearId);
        $available = $this->recommendations->availableForStudent($studentId, $schoolYearId, $projectedGrade, false);

        return [
            'child' => $child,
            'school_year' => $schoolYear,
            'projected_grade' => $projectedGrade,
            'active_reservation' => $activeReservation,
            'recommended' => $this->ranker->recommend($available, max(1, $recommendationCount)),
            'available' => $available,
        ];
    }

    public function reserve(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        int $lockerId,
    ): int {
        $this->linkedChild($parent, $studentId);
        $this->bookableSchoolYear($schoolYearId);

        return $this->reservations->reserve($studentId, $schoolYearId, $lockerId, null, false);
    }

    /** @return array<string, mixed> */
    public function cancelReservation(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        int $reservationId,
    ): array {
        $this->linkedChild($parent, $studentId);
        $active = $this->reservations->activeForStudent($studentId, $schoolYearId);
        if ($active === null || (int) $active['reservation_id'] !== $reservationId) {
            throw new DomainException('Die angegebene Reservierung ist nicht aktiv.');
        }
        if ((string) $active['status'] !== ReservationStatus::Active->value) {
            throw new DomainException('Eine bereits gestartete Zahlung kann nicht durch eine neue Auswahl aufgehoben werden.');
        }

        $this->reservations->cancel($reservationId, $studentId);

        return $active;
    }

    /**
     * @return array{id: int, label: string, starts_on: string, ends_on: string, annual_fee_cents: int}
     */
    private function bookableSchoolYear(int $schoolYearId): array
    {
        if ($schoolYearId < 1) {
            throw new DomainException('Das Schuljahr ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id, label, starts_on, ends_on, annual_fee_cents FROM school_years '
            . "WHERE id = :id AND status <> 'closed' AND ends_on >= CURRENT_DATE "
            . 'AND new_booking_opens_on <= CURRENT_DATE LIMIT 1'
        );
        $statement->execute(['id' => $schoolYearId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Dieses Schuljahr ist derzeit nicht für Neubuchungen geöffnet.');
        }

        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
        ];
    }
}
