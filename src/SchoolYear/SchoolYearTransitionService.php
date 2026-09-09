<?php

declare(strict_types=1);

namespace FachDock\SchoolYear;

use DateTimeImmutable;
use DomainException;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Mail\MailQueueService;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class SchoolYearTransitionService
{
    /** @var array<string, string> */
    private const REMINDER_DATES = [
        'june_01' => '06-01',
        'july_01' => '07-01',
        'july_20' => '07-20',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AllocationRuleEvaluator $allocationRules,
        private readonly MailQueueService $mailQueue,
        private readonly string $baseUrl,
        private readonly string $schoolName,
    ) {
    }

    /**
     * @return array{
     *   source:array<string,mixed>,target:array<string,mixed>,active_source:int,already_target:int,
     *   same_locker_possible:int,requires_change:int,leaving_school:int,unrenewed:int
     * }
     */
    public function preview(int $sourceSchoolYearId, int $targetSchoolYearId): array
    {
        [$source, $target] = $this->yearPair($sourceSchoolYearId, $targetSchoolYearId);
        $rows = $this->sourceBookings($sourceSchoolYearId);
        $summary = [
            'source' => $source,
            'target' => $target,
            'active_source' => count($rows),
            'already_target' => 0,
            'same_locker_possible' => 0,
            'requires_change' => 0,
            'leaving_school' => 0,
            'unrenewed' => 0,
        ];
        foreach ($rows as $row) {
            $targetState = $this->targetState($row, $targetSchoolYearId, (string) $target['starts_on']);
            if ($targetState['already_target']) {
                ++$summary['already_target'];
                continue;
            }
            ++$summary['unrenewed'];
            if ($targetState['leaving_school']) {
                ++$summary['leaving_school'];
            } elseif ($targetState['same_locker_possible']) {
                ++$summary['same_locker_possible'];
            } else {
                ++$summary['requires_change'];
            }
        }

        return $summary;
    }

    /**
     * Run all due reminder stages and an overdue rollover when necessary.
     * @return array{reminders:int,rollovers:int,reminder_stages:list<string>}
     */
    public function dailyTick(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $reminders = 0;
        $stages = [];

        $year = (int) $today->format('Y');
        $source = $this->yearByEndDate(sprintf('%04d-07-31', $year));
        $target = $this->yearByStartDate(sprintf('%04d-08-01', $year));
        if ($source !== null && $target !== null && $today <= new DateTimeImmutable((string) $source['ends_on'])) {
            foreach (self::REMINDER_DATES as $key => $monthDay) {
                $due = new DateTimeImmutable(sprintf('%04d-%s', $year, $monthDay));
                if ($today >= $due) {
                    $count = $this->queueReminderStage((int) $source['id'], (int) $target['id'], $key);
                    $reminders += $count;
                    if ($count > 0) {
                        $stages[] = $key;
                    }
                }
            }
        }

        $rollovers = 0;
        $pairs = $this->overdueRolloverPairs($today);
        foreach ($pairs as $pair) {
            $this->applyRollover(
                (int) $pair['source_id'],
                (int) $pair['target_id'],
                'system',
                null,
                $today,
            );
            ++$rollovers;
        }

        return ['reminders' => $reminders, 'rollovers' => $rollovers, 'reminder_stages' => $stages];
    }

    public function queueReminderStage(int $sourceSchoolYearId, int $targetSchoolYearId, string $reminderKey): int
    {
        if (!array_key_exists($reminderKey, self::REMINDER_DATES)) {
            throw new DomainException('Die Erinnerungsstufe ist ungültig.');
        }
        [$source, $target] = $this->yearPair($sourceSchoolYearId, $targetSchoolYearId);
        $rows = $this->sourceBookings($sourceSchoolYearId);
        $queued = 0;

        foreach ($rows as $booking) {
            $state = $this->targetState($booking, $targetSchoolYearId, (string) $target['starts_on']);
            if ($state['already_target'] || $state['leaving_school']) {
                continue;
            }
            $parents = $this->parentsForStudent((int) $booking['student_id']);
            foreach ($parents as $parent) {
                if ($this->reminderAlreadyDispatched(
                    $sourceSchoolYearId,
                    $targetSchoolYearId,
                    (int) $booking['student_id'],
                    (int) $parent['id'],
                    $reminderKey,
                )) {
                    continue;
                }
                $sameLockerMessage = $state['same_locker_possible']
                    ? 'Das bisherige Schließfach ' . (string) $booking['locker_name']
                        . ' ist für die nächste Klassenstufe derzeit weiterhin verfügbar.'
                    : 'Für die nächste Klassenstufe ist ein anderes Schließfach erforderlich oder das bisherige Fach ist nicht mehr verfügbar.';
                $queueId = $this->mailQueue->enqueue(
                    'school_year_renewal_reminder',
                    (string) $parent['email'],
                    $this->parentName($parent),
                    [
                        'student_name' => (string) $booking['student_name'],
                        'parent_name_suffix' => $this->parentNameSuffix($parent),
                        'source_end_date' => $this->formatDate((string) $source['ends_on']),
                        'renewal_message' => $sameLockerMessage,
                        'target_school_year' => (string) $target['label'],
                        'booking_url' => $this->absoluteUrl('/parent/booking?student_id=' . (int) $booking['student_id']
                            . '&school_year_id=' . $targetSchoolYearId),
                        'school_name' => $this->schoolName !== '' ? $this->schoolName : 'FachDock',
                    ],
                    [],
                    'student',
                    (int) $booking['student_id'],
                    'renewal:' . $sourceSchoolYearId . ':' . $targetSchoolYearId . ':' . (int) $booking['student_id'],
                    'school-year-reminder:' . $reminderKey . ':' . $sourceSchoolYearId . ':' . $targetSchoolYearId
                        . ':' . (int) $booking['student_id'] . ':' . (int) $parent['id'],
                    120,
                    (string) $source['ends_on'] . ' 23:59:59',
                );
                $this->recordReminder(
                    $sourceSchoolYearId,
                    $targetSchoolYearId,
                    (int) $booking['student_id'],
                    (int) $parent['id'],
                    $reminderKey,
                    $queueId,
                );
                ++$queued;
            }
        }

        return $queued;
    }

    /**
     * @return array{ended:int,with_target_booking:int,without_target_booking:int,notices_queued:int}
     * @throws JsonException
     */
    public function applyRollover(
        int $sourceSchoolYearId,
        int $targetSchoolYearId,
        string $actorType,
        ?int $actorId,
        ?DateTimeImmutable $today = null,
    ): array {
        $today ??= new DateTimeImmutable('today');
        [$source, $target] = $this->yearPair($sourceSchoolYearId, $targetSchoolYearId);
        if ($today < new DateTimeImmutable((string) $target['starts_on'])) {
            throw new DomainException('Der Schuljahreswechsel darf erst ab Beginn des Zielschuljahres durchgeführt werden.');
        }
        $existing = $this->completedRun($sourceSchoolYearId, $targetSchoolYearId);
        if ($existing !== null) {
            return $existing;
        }
        if (!in_array($actorType, ['staff', 'system'], true)) {
            throw new DomainException('Der Akteur des Schuljahreswechsels ist ungültig.');
        }
        if ($actorType === 'staff' && ($actorId === null || $actorId < 1)) {
            throw new DomainException('Für einen manuellen Schuljahreswechsel ist ein Administrator erforderlich.');
        }

        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare(
                'INSERT INTO school_year_transition_runs '
                . '(source_school_year_id, target_school_year_id, status, summary_json, initiated_by_type, initiated_by_id, started_at) '
                . "VALUES (:source_id, :target_id, 'running', '{}', :actor_type, :actor_id, CURRENT_TIMESTAMP)"
            );
            $run->execute([
                'source_id' => $sourceSchoolYearId,
                'target_id' => $targetSchoolYearId,
                'actor_type' => $actorType,
                'actor_id' => $actorType === 'staff' ? $actorId : null,
            ]);
            $runId = (int) $this->pdo->lastInsertId();
            if ($runId < 1) {
                throw new RuntimeException('Der Schuljahreswechsel konnte nicht protokolliert werden.');
            }

            $bookings = $this->sourceBookingsForUpdate($sourceSchoolYearId);
            $summary = ['ended' => 0, 'with_target_booking' => 0, 'without_target_booking' => 0, 'notices_queued' => 0];
            foreach ($bookings as $booking) {
                $hasTarget = $this->studentHasTargetBooking((int) $booking['student_id'], $targetSchoolYearId);
                if ($hasTarget) {
                    ++$summary['with_target_booking'];
                } else {
                    ++$summary['without_target_booking'];
                }

                $bookingId = (int) $booking['id'];
                $this->pdo->prepare('DELETE FROM booking_slots WHERE booking_id = :booking_id')
                    ->execute(['booking_id' => $bookingId]);
                $this->pdo->prepare('DELETE FROM locker_occupancies WHERE booking_id = :booking_id')
                    ->execute(['booking_id' => $bookingId]);
                $this->pdo->prepare(
                    'UPDATE locker_assignment_history SET ends_at = COALESCE(ends_at, :ends_at) '
                    . 'WHERE booking_id = :booking_id AND ends_at IS NULL'
                )->execute([
                    'ends_at' => (string) $target['starts_on'] . ' 00:00:00',
                    'booking_id' => $bookingId,
                ]);
                $this->cancelPendingPaymentMails($bookingId);
                $this->pdo->prepare(
                    "UPDATE bookings SET status = 'ended', valid_until = :valid_until, payment_due_at = NULL, "
                    . 'ended_at = :ended_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute([
                    'valid_until' => (string) $source['ends_on'],
                    'ended_at' => (string) $target['starts_on'] . ' 00:00:00',
                    'id' => $bookingId,
                ]);
                $this->pdo->prepare(
                    'INSERT INTO booking_lifecycle_events '
                    . '(booking_id, event_type, related_booking_id, old_locker_id, new_locker_id, effective_on, reason, '
                    . 'actor_type, actor_id, created_at) '
                    . "VALUES (:booking_id, 'school_year_ended', NULL, :locker_id, NULL, :effective_on, :reason, "
                    . ':actor_type, :actor_id, CURRENT_TIMESTAMP)'
                )->execute([
                    'booking_id' => $bookingId,
                    'locker_id' => (int) $booking['locker_id'],
                    'effective_on' => (string) $source['ends_on'],
                    'reason' => 'Automatischer Schuljahreswechsel von ' . (string) $source['label'] . ' nach ' . (string) $target['label'],
                    'actor_type' => $actorType,
                    'actor_id' => $actorType === 'staff' ? $actorId : null,
                ]);
                ++$summary['ended'];

                foreach ($this->parentsForStudent((int) $booking['student_id']) as $parent) {
                    $queueId = $this->mailQueue->enqueue(
                        'school_year_rollover_notice',
                        (string) $parent['email'],
                        $this->parentName($parent),
                        [
                            'student_name' => (string) $booking['student_name'],
                            'parent_name_suffix' => $this->parentNameSuffix($parent),
                            'source_school_year' => (string) $source['label'],
                            'next_step' => $hasTarget
                                ? 'Für das neue Schuljahr liegt bereits eine Buchung vor.'
                                : 'Für das neue Schuljahr liegt noch keine Buchung vor. Das bisherige Fach ist nicht mehr reserviert und kann inzwischen anderweitig vergeben sein.',
                            'booking_url' => $this->absoluteUrl('/parent'),
                            'school_name' => $this->schoolName !== '' ? $this->schoolName : 'FachDock',
                        ],
                        [],
                        'booking',
                        $bookingId,
                        'school-year-rollover:' . $bookingId,
                        'school-year-rollover:' . $sourceSchoolYearId . ':' . $targetSchoolYearId . ':' . $bookingId . ':' . (int) $parent['id'],
                        130,
                    );
                    unset($queueId);
                    ++$summary['notices_queued'];
                }
            }

            $this->pdo->prepare(
                "UPDATE school_years SET status = 'closed', closed_at = COALESCE(closed_at, CURRENT_TIMESTAMP), "
                . 'reopened_until = NULL, reopen_reason = NULL, reopened_by_staff_user_id = NULL, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :id'
            )->execute(['id' => $sourceSchoolYearId]);
            $this->pdo->prepare(
                "UPDATE school_years SET status = 'current', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute(['id' => $targetSchoolYearId]);

            $this->pdo->prepare(
                "UPDATE school_year_transition_runs SET status = 'completed', summary_json = :summary, "
                . 'finished_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([
                'id' => $runId,
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $this->pdo->commit();

            return $summary;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            'SELECT r.*, s.label AS source_label, t.label AS target_label, su.display_name AS staff_name '
            . 'FROM school_year_transition_runs r '
            . 'INNER JOIN school_years s ON s.id = r.source_school_year_id '
            . 'INNER JOIN school_years t ON t.id = r.target_school_year_id '
            . 'LEFT JOIN staff_users su ON su.id = r.initiated_by_id '
            . 'ORDER BY r.id DESC LIMIT ' . $limit
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schuljahreswechsel konnten nicht geladen werden.');
        }

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function transitionPairs(): array
    {
        $statement = $this->pdo->query(
            'SELECT s.id AS source_id, s.label AS source_label, s.starts_on AS source_starts_on, s.ends_on AS source_ends_on, '
            . 't.id AS target_id, t.label AS target_label, t.starts_on AS target_starts_on, t.ends_on AS target_ends_on '
            . 'FROM school_years s INNER JOIN school_years t ON t.starts_on = DATE_ADD(s.ends_on, INTERVAL 1 DAY) '
            . 'ORDER BY s.starts_on DESC LIMIT 6'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schuljahrespaare konnten nicht geladen werden.');
        }

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function yearPair(int $sourceId, int $targetId): array
    {
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) {
            throw new DomainException('Quell- oder Zielschuljahr ist ungültig.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, label, starts_on, ends_on, status, annual_fee_cents FROM school_years WHERE id IN (:source_id, :target_id)'
        );
        // MySQL native prepares cannot bind one placeholder twice; IDs are validated integers.
        $statement = $this->pdo->query(
            'SELECT id, label, starts_on, ends_on, status, annual_fee_cents FROM school_years WHERE id IN ('
            . $sourceId . ', ' . $targetId . ')'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schuljahre konnten nicht geladen werden.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        if (!isset($byId[$sourceId], $byId[$targetId])) {
            throw new DomainException('Quell- oder Zielschuljahr existiert nicht.');
        }
        $source = $byId[$sourceId];
        $target = $byId[$targetId];
        $expected = (new DateTimeImmutable((string) $source['ends_on']))->modify('+1 day')->format('Y-m-d');
        if ((string) $target['starts_on'] !== $expected) {
            throw new DomainException('Schuljahreswechsel sind nur zwischen unmittelbar aufeinanderfolgenden Schuljahren möglich.');
        }

        return [$source, $target];
    }

    /** @return list<array<string,mixed>> */
    private function sourceBookings(int $sourceSchoolYearId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.student_id, b.projected_grade, b.status, lo.locker_id, l.short_name AS locker_name, '
            . "CONCAT(s.first_name, ' ', s.last_name) AS student_name "
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id INNER JOIN lockers l ON l.id = lo.locker_id '
            . "WHERE b.school_year_id = :school_year_id AND b.status IN ('active','exemption_review','payment_due') "
            . 'ORDER BY b.id'
        );
        $statement->execute(['school_year_id' => $sourceSchoolYearId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function sourceBookingsForUpdate(int $sourceSchoolYearId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.student_id, b.projected_grade, b.status, lo.locker_id, l.short_name AS locker_name, '
            . "CONCAT(s.first_name, ' ', s.last_name) AS student_name "
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id INNER JOIN lockers l ON l.id = lo.locker_id '
            . "WHERE b.school_year_id = :school_year_id AND b.status IN ('active','exemption_review','payment_due') "
            . 'ORDER BY b.id FOR UPDATE'
        );
        $statement->execute(['school_year_id' => $sourceSchoolYearId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $booking
     * @return array{already_target:bool,leaving_school:bool,same_locker_possible:bool}
     */
    private function targetState(array $booking, int $targetSchoolYearId, string $targetStartsOn): array
    {
        if ($this->studentHasTargetBooking((int) $booking['student_id'], $targetSchoolYearId)) {
            return ['already_target' => true, 'leaving_school' => false, 'same_locker_possible' => false];
        }
        $sourceStartYear = (int) substr($targetStartsOn, 0, 4) - 1;
        $targetStartYear = (int) substr($targetStartsOn, 0, 4);
        $delta = max(1, $targetStartYear - $sourceStartYear);
        $projectedGrade = (int) $booking['projected_grade'] + $delta;
        if ($projectedGrade > 12) {
            return ['already_target' => false, 'leaving_school' => true, 'same_locker_possible' => false];
        }
        $lockerId = (int) $booking['locker_id'];
        $statement = $this->pdo->prepare(
            'SELECT l.id FROM lockers l '
            . 'LEFT JOIN locker_occupancies lo ON lo.school_year_id = :year_id AND lo.locker_id = l.id '
            . 'LEFT JOIN reservation_slots rs ON rs.school_year_id = :year_id_2 AND rs.locker_id = l.id '
            . 'WHERE l.id = :locker_id AND l.active = 1 AND l.bookable = 1 '
            . "AND l.operating_status = 'operational' AND lo.locker_id IS NULL AND rs.locker_id IS NULL"
        );
        $statement->execute(['year_id' => $targetSchoolYearId, 'year_id_2' => $targetSchoolYearId, 'locker_id' => $lockerId]);
        if ($statement->fetchColumn() === false) {
            return ['already_target' => false, 'leaving_school' => false, 'same_locker_possible' => false];
        }
        $decision = $this->allocationRules->evaluate($targetSchoolYearId, $projectedGrade, $lockerId);

        return ['already_target' => false, 'leaving_school' => false, 'same_locker_possible' => $decision->allowed];
    }

    private function studentHasTargetBooking(int $studentId, int $targetSchoolYearId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM booking_slots WHERE school_year_id = :school_year_id AND student_id = :student_id LIMIT 1'
        );
        $statement->execute(['school_year_id' => $targetSchoolYearId, 'student_id' => $studentId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{id:int,email:string,first_name:string|null,last_name:string|null}> */
    private function parentsForStudent(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pc.id, pc.email, pc.first_name, pc.last_name '
            . 'FROM parent_student_link_slots psls INNER JOIN parent_contacts pc ON pc.id = psls.parent_contact_id '
            . 'WHERE psls.student_id = :student_id AND pc.active = 1 AND pc.verified_at IS NOT NULL ORDER BY pc.id'
        );
        $statement->execute(['student_id' => $studentId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'first_name' => $row['first_name'] !== null ? (string) $row['first_name'] : null,
                'last_name' => $row['last_name'] !== null ? (string) $row['last_name'] : null,
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function reminderAlreadyDispatched(int $sourceId, int $targetId, int $studentId, int $parentId, string $key): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM school_year_reminder_dispatches '
            . 'WHERE source_school_year_id = :source_id AND target_school_year_id = :target_id '
            . 'AND student_id = :student_id AND parent_contact_id = :parent_id AND reminder_key = :reminder_key LIMIT 1'
        );
        $statement->execute([
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'student_id' => $studentId,
            'parent_id' => $parentId,
            'reminder_key' => $key,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function recordReminder(int $sourceId, int $targetId, int $studentId, int $parentId, string $key, int $queueId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO school_year_reminder_dispatches '
            . '(source_school_year_id, target_school_year_id, student_id, parent_contact_id, reminder_key, mail_queue_id, created_at) '
            . 'VALUES (:source_id, :target_id, :student_id, :parent_id, :reminder_key, :queue_id, CURRENT_TIMESTAMP)'
        );
        try {
            $statement->execute([
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'student_id' => $studentId,
                'parent_id' => $parentId,
                'reminder_key' => $key,
                'queue_id' => $queueId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function yearByEndDate(string $date): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, label, starts_on, ends_on, status FROM school_years WHERE ends_on = :date LIMIT 1');
        $statement->execute(['date' => $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function yearByStartDate(string $date): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, label, starts_on, ends_on, status FROM school_years WHERE starts_on = :date LIMIT 1');
        $statement->execute(['date' => $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array{source_id:int,target_id:int}> */
    private function overdueRolloverPairs(DateTimeImmutable $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id AS source_id, t.id AS target_id '
            . 'FROM school_years s INNER JOIN school_years t ON t.starts_on = DATE_ADD(s.ends_on, INTERVAL 1 DAY) '
            . 'WHERE t.starts_on <= :today '
            . 'AND EXISTS (SELECT 1 FROM bookings b WHERE b.school_year_id = s.id '
            . "AND b.status IN ('active','exemption_review','payment_due')) "
            . 'AND NOT EXISTS (SELECT 1 FROM school_year_transition_runs r '
            . "WHERE r.source_school_year_id = s.id AND r.target_school_year_id = t.id AND r.status = 'completed') "
            . 'ORDER BY t.starts_on ASC'
        );
        $statement->execute(['today' => $today->format('Y-m-d')]);

        return array_map(
            static fn (array $row): array => ['source_id' => (int) $row['source_id'], 'target_id' => (int) $row['target_id']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return array{ended:int,with_target_booking:int,without_target_booking:int,notices_queued:int}|null */
    private function completedRun(int $sourceId, int $targetId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT summary_json FROM school_year_transition_runs '
            . "WHERE source_school_year_id = :source_id AND target_school_year_id = :target_id AND status = 'completed' "
            . 'ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['source_id' => $sourceId, 'target_id' => $targetId]);
        $json = $statement->fetchColumn();
        if ($json === false) {
            return null;
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'ended' => (int) ($decoded['ended'] ?? 0),
            'with_target_booking' => (int) ($decoded['with_target_booking'] ?? 0),
            'without_target_booking' => (int) ($decoded['without_target_booking'] ?? 0),
            'notices_queued' => (int) ($decoded['notices_queued'] ?? 0),
        ];
    }

    private function cancelPendingPaymentMails(int $bookingId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE mail_queue SET status = 'canceled', canceled_at = CURRENT_TIMESTAMP, html_body = NULL, text_body = NULL, "
            . 'locked_at = NULL, updated_at = CURRENT_TIMESTAMP '
            . "WHERE relation_type = 'booking' AND relation_id = :booking_id AND status = 'waiting' "
            . "AND business_reference LIKE 'payment_due_reminder:%'"
        );
        $statement->execute(['booking_id' => $bookingId]);
    }

    /** @param array{id:int,email:string,first_name:string|null,last_name:string|null} $parent */
    private function parentName(array $parent): ?string
    {
        $name = trim((string) ($parent['first_name'] ?? '') . ' ' . (string) ($parent['last_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /** @param array{id:int,email:string,first_name:string|null,last_name:string|null} $parent */
    private function parentNameSuffix(array $parent): string
    {
        $name = $this->parentName($parent);

        return $name !== null ? ' ' . $name : '';
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim(trim($this->baseUrl), '/');

        return $base !== '' ? $base . $path : $path;
    }

    private function formatDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('d.m.Y') : $date;
    }
}
