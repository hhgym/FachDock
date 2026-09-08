<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\Location\LockerNaming;
use PDO;
use RuntimeException;

final class LockerRecommendationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $evaluator,
        private readonly LockerRecommendationRanker $ranker = new LockerRecommendationRanker(),
        private readonly ProjectedGradeResolver $gradeResolver = new ProjectedGradeResolver(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recommendForStudent(
        int $studentId,
        int $schoolYearId,
        ?int $projectedGrade = null,
        int $limit = 3,
        bool $allowBeforeOpening = false,
    ): array {
        return $this->ranker->recommend(
            $this->availableForStudent($studentId, $schoolYearId, $projectedGrade, $allowBeforeOpening),
            $limit,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableForStudent(
        int $studentId,
        int $schoolYearId,
        ?int $projectedGrade = null,
        bool $allowBeforeOpening = false,
    ): array {
        $student = $this->student($studentId);
        $schoolYear = $this->schoolYearAvailability($schoolYearId, $allowBeforeOpening);
        $grade = $projectedGrade ?? $this->gradeResolver->resolve(
            (int) $student['grade'],
            $schoolYear['starts_on'],
        );
        if ($grade < 5 || $grade > 12) {
            throw new DomainException('Die prognostizierte Klassenstufe muss zwischen 5 und 12 liegen.');
        }

        $this->assertStudentHasNoBooking($studentId, $schoolYearId);

        $statement = $this->pdo->prepare(
            'SELECT l.id AS locker_id, l.short_name, l.barrier_friendly, '
            . 'l.position_no AS locker_position, c.position_no AS corpus_position, '
            . 'cg.id AS cabinet_group_id, cg.code AS group_code, '
            . 'a.id AS area_id, a.code AS area_code, a.name AS area_name, '
            . 'f.id AS floor_id, f.code AS floor_code, f.name AS floor_name, '
            . 'b.id AS building_id, b.code AS building_code, b.name AS building_name '
            . 'FROM lockers l '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id '
            . 'INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings b ON b.id = f.building_id '
            . 'WHERE l.active = 1 AND l.bookable = 1 AND l.operating_status = \'operational\' '
            . 'AND c.active = 1 AND cg.active = 1 AND a.active = 1 AND f.active = 1 AND b.active = 1 '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM locker_occupancies lo '
            . 'WHERE lo.school_year_id = :occupancy_school_year_id AND lo.locker_id = l.id'
            . ') '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM reservation_slots rs '
            . 'INNER JOIN locker_reservations lr ON lr.id = rs.reservation_id '
            . 'WHERE rs.school_year_id = :reservation_school_year_id AND rs.locker_id = l.id '
            . 'AND ((lr.status = \'active\' AND lr.expires_at > CURRENT_TIMESTAMP) '
            . 'OR (lr.status = \'payment_running\' AND lr.payment_grace_expires_at IS NOT NULL '
            . 'AND lr.payment_grace_expires_at > CURRENT_TIMESTAMP))'
            . ') '
            . 'ORDER BY f.sort_order, a.code, cg.code, c.position_no, l.position_no'
        );
        $statement->execute([
            'occupancy_school_year_id' => $schoolYearId,
            'reservation_school_year_id' => $schoolYearId,
        ]);

        $rows = [];
        $locations = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lockerId = (int) $row['locker_id'];
            $rows[$lockerId] = $row;
            $locations[] = [
                'locker_id' => $lockerId,
                'building_id' => (int) $row['building_id'],
                'floor_id' => (int) $row['floor_id'],
                'area_id' => (int) $row['area_id'],
                'cabinet_group_id' => (int) $row['cabinet_group_id'],
            ];
        }

        $decisions = $this->evaluator->evaluateLocations($schoolYearId, $grade, $locations);
        $result = [];
        foreach ($rows as $lockerId => $row) {
            $decision = $decisions[$lockerId] ?? null;
            if ($decision === null || !$decision->allowed) {
                continue;
            }

            $result[] = [
                'locker_id' => $lockerId,
                'short_name' => (string) $row['short_name'],
                'long_name' => LockerNaming::longName(
                    (string) $row['floor_code'],
                    (string) $row['area_code'],
                    (string) $row['group_code'],
                    (int) $row['corpus_position'],
                    (int) $row['locker_position'],
                ),
                'score' => $decision->score,
                'barrier_friendly' => (int) $row['barrier_friendly'] === 1,
                'building_code' => (string) $row['building_code'],
                'building_name' => (string) $row['building_name'],
                'floor_code' => (string) $row['floor_code'],
                'floor_name' => (string) $row['floor_name'],
                'area_code' => (string) $row['area_code'],
                'area_name' => (string) $row['area_name'],
                'group_code' => (string) $row['group_code'],
                'matched_soft_rule_ids' => $decision->snapshot['matched_soft_rule_ids'] ?? [],
                'projected_grade' => $grade,
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $score = ((int) $right['score']) <=> ((int) $left['score']);
            if ($score !== 0) {
                return $score;
            }

            return strnatcasecmp((string) $left['short_name'], (string) $right['short_name']);
        });

        return $result;
    }

    public function projectedGradeForStudent(int $studentId, int $schoolYearId): int
    {
        $student = $this->student($studentId);
        $schoolYear = $this->schoolYearAvailability($schoolYearId, true);

        return $this->gradeResolver->resolve((int) $student['grade'], $schoolYear['starts_on']);
    }

    /** @return list<array{id: int, matrikelnummer: string, first_name: string, last_name: string, class_name: string, grade: int}> */
    public function activeStudents(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, matrikelnummer, first_name, last_name, class_name, grade '
            . 'FROM students WHERE active = 1 ORDER BY grade, class_name, last_name, first_name, id'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schülerliste konnte nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'matrikelnummer' => (string) $row['matrikelnummer'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'class_name' => (string) $row['class_name'],
                'grade' => (int) $row['grade'],
            ];
        }

        return $result;
    }

    /** @return array{id: int, matrikelnummer: string, first_name: string, last_name: string, class_name: string, grade: int} */
    public function student(int $studentId): array
    {
        if ($studentId < 1) {
            throw new DomainException('Der Schüler ist ungültig.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, matrikelnummer, first_name, last_name, class_name, grade '
            . 'FROM students WHERE id = :id AND active = 1'
        );
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Der Schüler ist nicht für eine Buchung verfügbar.');
        }

        return [
            'id' => (int) $row['id'],
            'matrikelnummer' => (string) $row['matrikelnummer'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'class_name' => (string) $row['class_name'],
            'grade' => (int) $row['grade'],
        ];
    }

    /** @return array{starts_on: string} */
    private function schoolYearAvailability(int $schoolYearId, bool $allowBeforeOpening): array
    {
        if ($schoolYearId < 1) {
            throw new DomainException('Das Schuljahr ist ungültig.');
        }
        $statement = $this->pdo->prepare(
            'SELECT status, starts_on, ends_on, new_booking_opens_on FROM school_years WHERE id = :id'
        );
        $statement->execute(['id' => $schoolYearId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Das Schuljahr existiert nicht.');
        }
        if ((string) $row['status'] === 'closed' || (string) $row['ends_on'] < date('Y-m-d')) {
            throw new DomainException('Das Schuljahr ist bereits geschlossen.');
        }
        if (!$allowBeforeOpening && (string) $row['new_booking_opens_on'] > date('Y-m-d')) {
            throw new DomainException('Reguläre Neubuchungen für dieses Schuljahr sind noch nicht geöffnet.');
        }

        return ['starts_on' => (string) $row['starts_on']];
    }

    private function assertStudentHasNoBooking(int $studentId, int $schoolYearId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT booking_id FROM booking_slots '
            . 'WHERE student_id = :student_id AND school_year_id = :school_year_id'
        );
        $statement->execute([
            'student_id' => $studentId,
            'school_year_id' => $schoolYearId,
        ]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Für den Schüler besteht in diesem Schuljahr bereits eine Buchung.');
        }
    }
}
