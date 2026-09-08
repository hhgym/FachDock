<?php

declare(strict_types=1);

namespace FachDock\SchoolYear;

use DateTimeImmutable;
use DomainException;
use FachDock\Booking\SchoolYearPeriod;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class SchoolYearService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<int> */
    public function ensureCurrentAndNext(): array
    {
        $current = SchoolYearPeriod::containing(new DateTimeImmutable('today'));
        $created = [];

        foreach ([$current->startYear, $current->startYear + 1] as $startYear) {
            $id = $this->createIfMissing($startYear);
            if ($id !== null) {
                $created[] = $id;
            }
        }

        $this->synchronizeStatuses();

        return $created;
    }

    public function create(int $startYear, int $annualFeeCents = 0, int $maxParentChanges = 2): int
    {
        $this->assertBusinessSettings($annualFeeCents, $maxParentChanges);
        $period = SchoolYearPeriod::fromStartYear($startYear);

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO school_years '
                . '(label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, '
                . 'max_parent_changes, created_at, updated_at) '
                . "VALUES (:label, :starts_on, :ends_on, 'future', :opens_on, :annual_fee_cents, "
                . ':max_parent_changes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'label' => $period->label,
                'starts_on' => $period->startsOn->format('Y-m-d'),
                'ends_on' => $period->endsOn->format('Y-m-d'),
                'opens_on' => $period->startsOn->format('Y-m-d'),
                'annual_fee_cents' => $annualFeeCents,
                'max_parent_changes' => $maxParentChanges,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException('Dieses Schuljahr ist bereits vorhanden.', 0, $exception);
            }
            throw $exception;
        }

        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Das Schuljahr konnte nicht angelegt werden.');
        }

        $this->synchronizeStatuses();

        return $id;
    }

    public function synchronizeStatuses(): void
    {
        $this->pdo->exec(
            "UPDATE school_years SET status = 'closed', closed_at = COALESCE(closed_at, CURRENT_TIMESTAMP), "
            . 'reopened_until = NULL, reopen_reason = NULL, reopened_by_staff_user_id = NULL, '
            . 'updated_at = CURRENT_TIMESTAMP '
            . "WHERE status <> 'closed' AND ends_on < CURRENT_DATE"
        );
        $this->pdo->exec(
            "UPDATE school_years SET status = 'current', updated_at = CURRENT_TIMESTAMP "
            . "WHERE status = 'future' AND starts_on <= CURRENT_DATE AND ends_on >= CURRENT_DATE"
        );
        $this->pdo->exec(
            'UPDATE school_years SET reopened_until = NULL, reopen_reason = NULL, '
            . 'reopened_by_staff_user_id = NULL, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE reopened_until IS NOT NULL AND reopened_until <= CURRENT_TIMESTAMP'
        );
    }

    public function updateSettings(
        int $schoolYearId,
        string $newBookingOpensOn,
        int $annualFeeCents,
        int $maxParentChanges,
    ): void {
        $this->assertBusinessSettings($annualFeeCents, $maxParentChanges);
        $openingDate = $this->date($newBookingOpensOn, 'Öffnungsdatum');

        $this->pdo->beginTransaction();
        try {
            $row = $this->loadForUpdate($schoolYearId);
            if (!$row['editable']) {
                throw new DomainException(
                    'Das Schuljahr ist geschlossen. Für Korrekturen muss es vorübergehend wieder geöffnet werden.'
                );
            }
            if ($openingDate > new DateTimeImmutable($row['ends_on'])) {
                throw new DomainException('Das Öffnungsdatum darf nicht nach dem Ende des Schuljahres liegen.');
            }

            $statement = $this->pdo->prepare(
                'UPDATE school_years SET new_booking_opens_on = :opens_on, annual_fee_cents = :annual_fee_cents, '
                . 'max_parent_changes = :max_parent_changes, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $schoolYearId,
                'opens_on' => $openingDate->format('Y-m-d'),
                'annual_fee_cents' => $annualFeeCents,
                'max_parent_changes' => $maxParentChanges,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function close(int $schoolYearId): void
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->loadForUpdate($schoolYearId);
            if ($row['status'] === SchoolYearStatus::Closed->value) {
                throw new DomainException('Das Schuljahr ist bereits geschlossen.');
            }

            $statement = $this->pdo->prepare(
                "UPDATE school_years SET status = 'closed', closed_at = CURRENT_TIMESTAMP, "
                . 'reopened_until = NULL, reopen_reason = NULL, reopened_by_staff_user_id = NULL, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute(['id' => $schoolYearId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function reopenForCorrection(int $schoolYearId, int $minutes, string $reason, int $staffUserId): void
    {
        $reason = trim($reason);
        if ($minutes < 5 || $minutes > 1440) {
            throw new DomainException('Die Korrekturöffnung muss zwischen 5 Minuten und 24 Stunden liegen.');
        }
        if ($reason === '') {
            throw new DomainException('Für die Korrekturöffnung ist eine Begründung erforderlich.');
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->loadForUpdate($schoolYearId);
            if ($row['status'] !== SchoolYearStatus::Closed->value) {
                throw new DomainException('Nur ein geschlossenes Schuljahr kann zur Korrektur geöffnet werden.');
            }

            $statement = $this->pdo->prepare(
                'UPDATE school_years SET reopened_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL '
                . $minutes . ' MINUTE), reopen_reason = :reason, reopened_by_staff_user_id = :staff_user_id, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'id' => $schoolYearId,
                'reason' => $reason,
                'staff_user_id' => $staffUserId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function endCorrectionReopen(int $schoolYearId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE school_years SET reopened_until = NULL, reopen_reason = NULL, '
            . 'reopened_by_staff_user_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $schoolYearId]);
        if ($statement->rowCount() === 0 && $this->find($schoolYearId) === null) {
            throw new DomainException('Das Schuljahr existiert nicht.');
        }
    }

    /**
     * @return list<array{
     *     id: int,
     *     label: string,
     *     starts_on: string,
     *     ends_on: string,
     *     status: string,
     *     new_booking_opens_on: string,
     *     annual_fee_cents: int,
     *     max_parent_changes: int,
     *     closed_at: string|null,
     *     reopened_until: string|null,
     *     reopen_reason: string|null,
     *     reopened_by_name: string|null,
     *     editable: bool
     * }>
     */
    public function all(): array
    {
        $this->synchronizeStatuses();
        $statement = $this->pdo->query(
            "SELECT sy.id, sy.label, sy.starts_on, sy.ends_on, sy.status, sy.new_booking_opens_on, "
            . 'sy.annual_fee_cents, sy.max_parent_changes, sy.closed_at, sy.reopened_until, sy.reopen_reason, '
            . 'su.display_name AS reopened_by_name, '
            . "CASE WHEN sy.status <> 'closed' OR sy.reopened_until > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS editable "
            . 'FROM school_years sy LEFT JOIN staff_users su ON su.id = sy.reopened_by_staff_user_id '
            . 'ORDER BY sy.starts_on DESC'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schuljahre konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = $this->normalize($row);
        }

        return $result;
    }

    /**
     * @return array{
     *     id: int,
     *     label: string,
     *     starts_on: string,
     *     ends_on: string,
     *     status: string,
     *     new_booking_opens_on: string,
     *     annual_fee_cents: int,
     *     max_parent_changes: int,
     *     closed_at: string|null,
     *     reopened_until: string|null,
     *     reopen_reason: string|null,
     *     reopened_by_name: string|null,
     *     editable: bool
     * }|null
     */
    public function find(int $schoolYearId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT sy.id, sy.label, sy.starts_on, sy.ends_on, sy.status, sy.new_booking_opens_on, "
            . 'sy.annual_fee_cents, sy.max_parent_changes, sy.closed_at, sy.reopened_until, sy.reopen_reason, '
            . 'su.display_name AS reopened_by_name, '
            . "CASE WHEN sy.status <> 'closed' OR sy.reopened_until > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS editable "
            . 'FROM school_years sy LEFT JOIN staff_users su ON su.id = sy.reopened_by_staff_user_id '
            . 'WHERE sy.id = :id'
        );
        $statement->execute(['id' => $schoolYearId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    private function createIfMissing(int $startYear): ?int
    {
        $period = SchoolYearPeriod::fromStartYear($startYear);
        $lookup = $this->pdo->prepare('SELECT id FROM school_years WHERE starts_on = :starts_on');
        $lookup->execute(['starts_on' => $period->startsOn->format('Y-m-d')]);
        if ($lookup->fetchColumn() !== false) {
            return null;
        }

        try {
            return $this->create($startYear);
        } catch (DomainException $exception) {
            $lookup->execute(['starts_on' => $period->startsOn->format('Y-m-d')]);
            if ($lookup->fetchColumn() !== false) {
                return null;
            }
            throw $exception;
        }
    }

    /**
     * @return array{status: string, ends_on: string, editable: bool}
     */
    private function loadForUpdate(int $schoolYearId): array
    {
        if ($schoolYearId < 1) {
            throw new DomainException('Das Schuljahr ist ungültig.');
        }

        $statement = $this->pdo->prepare(
            "SELECT status, ends_on, CASE WHEN status <> 'closed' OR reopened_until > CURRENT_TIMESTAMP "
            . 'THEN 1 ELSE 0 END AS editable FROM school_years WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $schoolYearId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Das Schuljahr existiert nicht.');
        }

        return [
            'status' => (string) $row['status'],
            'ends_on' => (string) $row['ends_on'],
            'editable' => (int) $row['editable'] === 1,
        ];
    }

    private function date(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new DomainException($label . ' ist ungültig.');
        }

        return $date;
    }

    private function assertBusinessSettings(int $annualFeeCents, int $maxParentChanges): void
    {
        if ($annualFeeCents < 0 || $annualFeeCents > 100000000) {
            throw new DomainException('Der Jahresbeitrag ist ungültig.');
        }
        if ($maxParentChanges < 0 || $maxParentChanges > 100) {
            throw new DomainException('Die maximale Anzahl der Elternwechsel muss zwischen 0 und 100 liegen.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: int,
     *     label: string,
     *     starts_on: string,
     *     ends_on: string,
     *     status: string,
     *     new_booking_opens_on: string,
     *     annual_fee_cents: int,
     *     max_parent_changes: int,
     *     closed_at: string|null,
     *     reopened_until: string|null,
     *     reopen_reason: string|null,
     *     reopened_by_name: string|null,
     *     editable: bool
     * }
     */
    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'],
            'status' => (string) $row['status'],
            'new_booking_opens_on' => (string) $row['new_booking_opens_on'],
            'annual_fee_cents' => (int) $row['annual_fee_cents'],
            'max_parent_changes' => (int) $row['max_parent_changes'],
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            'reopened_until' => $row['reopened_until'] !== null ? (string) $row['reopened_until'] : null,
            'reopen_reason' => $row['reopen_reason'] !== null ? (string) $row['reopen_reason'] : null,
            'reopened_by_name' => $row['reopened_by_name'] !== null ? (string) $row['reopened_by_name'] : null,
            'editable' => (int) $row['editable'] === 1,
        ];
    }
}
