<?php

declare(strict_types=1);

namespace FachDock\Operations;

use DomainException;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Location\LockerOperatingStatus;
use FachDock\Parent\AuthenticatedParent;
use PDO;
use RuntimeException;
use Throwable;

final class LockerSupportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LockerSupportNotificationService $notifications,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function reportableForParent(int $parentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS booking_id, b.student_id, s.first_name, s.last_name, s.class_name, '
            . 'l.id AS locker_id, l.short_name AS locker_name, l.operating_status, sy.label AS school_year_label '
            . 'FROM parent_student_link_slots psl '
            . 'INNER JOIN students s ON s.id = psl.student_id AND s.active = 1 '
            . 'INNER JOIN bookings b ON b.student_id = s.id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'WHERE psl.parent_contact_id = :parent_id '
            . "AND b.status IN ('active', 'payment_due', 'exemption_review') "
            . 'AND CURRENT_DATE BETWEEN b.valid_from AND b.valid_until '
            . 'ORDER BY s.last_name, s.first_name, b.id DESC'
        );
        $statement->execute(['parent_id' => $parentId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function reportableForStudent(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS booking_id, b.student_id, s.first_name, s.last_name, s.class_name, '
            . 'l.id AS locker_id, l.short_name AS locker_name, l.operating_status, sy.label AS school_year_label '
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id AND s.active = 1 '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'WHERE b.student_id = :student_id '
            . "AND b.status IN ('active', 'payment_due', 'exemption_review') "
            . 'AND CURRENT_DATE BETWEEN b.valid_from AND b.valid_until '
            . 'ORDER BY b.id DESC'
        );
        $statement->execute(['student_id' => $studentId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function reportFromParent(
        AuthenticatedParent $parent,
        int $bookingId,
        string $categoryValue,
        string $description,
    ): int {
        $assignment = $this->assignmentForParent($parent->id, $bookingId);
        $category = $this->category($categoryValue);
        $reporterName = $parent->displayName();

        $id = $this->createIncident(
            (int) $assignment['locker_id'],
            (int) $assignment['booking_id'],
            (int) $assignment['student_id'],
            $category,
            $description,
            'parent',
            $parent->id,
            (string) $assignment['reporter_email'],
            $reporterName,
        );
        $incident = $this->incident($id);
        $this->notifications->received($incident);

        return $id;
    }

    public function reportFromStudent(
        int $studentId,
        int $bookingId,
        string $categoryValue,
        string $description,
    ): int {
        $assignment = $this->assignmentForStudent($studentId, $bookingId);
        $category = $this->category($categoryValue);
        $reporterName = trim((string) $assignment['first_name'] . ' ' . (string) $assignment['last_name']);

        $id = $this->createIncident(
            (int) $assignment['locker_id'],
            (int) $assignment['booking_id'],
            $studentId,
            $category,
            $description,
            'student',
            $studentId,
            $assignment['reporter_email'] === null ? null : (string) $assignment['reporter_email'],
            $reporterName,
        );
        $this->notifications->received($this->incident($id));

        return $id;
    }

    public function reportFromStaff(
        AuthenticatedStaff $staff,
        int $lockerId,
        string $categoryValue,
        string $description,
    ): int {
        $locker = $this->lockerWithCurrentAssignment($lockerId);
        $category = $this->category($categoryValue);

        return $this->createIncident(
            $lockerId,
            isset($locker['booking_id']) ? (int) $locker['booking_id'] : null,
            isset($locker['student_id']) ? (int) $locker['student_id'] : null,
            $category,
            $description,
            'staff',
            $staff->id,
            null,
            $staff->displayName,
        );
    }

    /** @return list<array<string, mixed>> */
    public function incidentsForStaff(?string $status = null): array
    {
        $where = '';
        $params = [];
        if ($status !== null && trim($status) !== '') {
            $parsed = LockerIncidentStatus::tryFrom(trim($status));
            if ($parsed === null) {
                throw new DomainException('Der Vorgangsstatus ist ungültig.');
            }
            $where = ' WHERE i.status = :status';
            $params['status'] = $parsed->value;
        }

        $statement = $this->pdo->prepare($this->incidentSelect() . $where
            . " ORDER BY (i.priority = 'urgent') DESC, (i.status IN ('open','in_progress','waiting')) DESC, i.opened_at DESC LIMIT 500");
        $statement->execute($params);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function incidentsForParent(int $parentId): array
    {
        $statement = $this->pdo->prepare(
            $this->incidentSelect()
            . ' INNER JOIN parent_student_link_slots psl_view ON psl_view.student_id = i.student_id '
            . 'WHERE psl_view.parent_contact_id = :parent_id ORDER BY i.opened_at DESC LIMIT 100'
        );
        $statement->execute(['parent_id' => $parentId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function incidentsForStudent(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            $this->incidentSelect() . ' WHERE i.student_id = :student_id ORDER BY i.opened_at DESC LIMIT 100'
        );
        $statement->execute(['student_id' => $studentId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    public function incident(int $incidentId): array
    {
        $statement = $this->pdo->prepare($this->incidentSelect() . ' WHERE i.id = :id');
        $statement->execute(['id' => $incidentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Der Schließfachvorgang wurde nicht gefunden.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $incidentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, event_type, old_status, new_status, note, actor_type, actor_id, created_at '
            . 'FROM locker_incident_events WHERE incident_id = :incident_id ORDER BY id ASC'
        );
        $statement->execute(['incident_id' => $incidentId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function operationHistory(int $lockerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, incident_id, event_type, old_operating_status, new_operating_status, '
            . 'old_bookable, new_bookable, note, actor_type, actor_id, created_at '
            . 'FROM locker_operation_events WHERE locker_id = :locker_id ORDER BY id DESC LIMIT 100'
        );
        $statement->execute(['locker_id' => $lockerId]);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function updateIncident(
        AuthenticatedStaff $staff,
        int $incidentId,
        string $statusValue,
        string $note,
    ): void {
        $status = LockerIncidentStatus::tryFrom(trim($statusValue));
        if ($status === null) {
            throw new DomainException('Der Vorgangsstatus ist ungültig.');
        }
        $note = $this->note($note, 4000);
        if ($status === LockerIncidentStatus::Resolved && $note === '') {
            throw new DomainException('Für einen erledigten Vorgang ist ein Abschlussvermerk erforderlich.');
        }

        $this->pdo->beginTransaction();
        try {
            $current = $this->lockIncident($incidentId);
            $oldStatus = LockerIncidentStatus::tryFrom((string) $current['status']);
            if ($oldStatus === null) {
                throw new RuntimeException('Unbekannter gespeicherter Vorgangsstatus.');
            }
            $closedAt = $status->closed() ? 'CURRENT_TIMESTAMP' : 'NULL';
            $statement = $this->pdo->prepare(
                'UPDATE locker_incidents SET status = :status, resolution_note = :resolution_note, '
                . 'resolved_at = ' . $closedAt . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'status' => $status->value,
                'resolution_note' => $status->closed() ? ($note !== '' ? $note : null) : null,
                'id' => $incidentId,
            ]);
            $this->insertIncidentEvent(
                $incidentId,
                'status_changed',
                $oldStatus->value,
                $status->value,
                $note,
                'staff',
                $staff->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($status === LockerIncidentStatus::Resolved) {
            $this->notifications->resolved($this->incident($incidentId));
        }
    }

    public function recordEmergencyOpening(AuthenticatedStaff $staff, int $incidentId, string $note): void
    {
        $note = $this->note($note, 4000);
        if ($note === '') {
            throw new DomainException('Bitte die Notöffnung kurz dokumentieren.');
        }

        $this->pdo->beginTransaction();
        try {
            $current = $this->lockIncident($incidentId);
            $category = LockerIncidentCategory::tryFrom((string) $current['category']);
            if (!in_array($category, [
                LockerIncidentCategory::EmergencyOpening,
                LockerIncidentCategory::CodeForgotten,
                LockerIncidentCategory::LockProblem,
            ], true)) {
                throw new DomainException('Dieser Vorgang ist keine Notöffnungsanfrage.');
            }
            $oldStatus = (string) $current['status'];
            $resolution = 'Notöffnung durchgeführt: ' . $note;
            $statement = $this->pdo->prepare(
                "UPDATE locker_incidents SET status = 'resolved', resolution_note = :resolution_note, "
                . 'resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute(['resolution_note' => $resolution, 'id' => $incidentId]);
            $this->insertIncidentEvent(
                $incidentId,
                'emergency_opening_performed',
                $oldStatus,
                LockerIncidentStatus::Resolved->value,
                $note,
                'staff',
                $staff->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->notifications->resolved($this->incident($incidentId));
    }

    public function updateLockerStatus(
        AuthenticatedStaff $staff,
        int $lockerId,
        string $statusValue,
        bool $bookable,
        string $note,
        ?int $incidentId = null,
    ): void {
        $status = LockerOperatingStatus::tryFrom(trim($statusValue));
        if ($status === null) {
            throw new DomainException('Der Betriebsstatus des Schließfachs ist ungültig.');
        }
        if ($status !== LockerOperatingStatus::Operational) {
            $bookable = false;
        }
        $note = $this->note($note, 4000);
        if ($note === '') {
            throw new DomainException('Bitte die Statusänderung begründen.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, operating_status, bookable FROM lockers WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $lockerId]);
            $locker = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locker)) {
                throw new DomainException('Das Schließfach wurde nicht gefunden.');
            }
            if ($incidentId !== null) {
                $incident = $this->lockIncident($incidentId);
                if ((int) $incident['locker_id'] !== $lockerId) {
                    throw new DomainException('Der Vorgang gehört nicht zu diesem Schließfach.');
                }
            }

            $oldStatus = (string) $locker['operating_status'];
            $oldBookable = (int) $locker['bookable'] === 1;
            $update = $this->pdo->prepare(
                'UPDATE lockers SET operating_status = :status, bookable = :bookable, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute([
                'status' => $status->value,
                'bookable' => $bookable ? 1 : 0,
                'id' => $lockerId,
            ]);
            $this->insertOperationEvent(
                $lockerId,
                $incidentId,
                'operating_status_changed',
                $oldStatus,
                $status->value,
                $oldBookable,
                $bookable,
                $note,
                'staff',
                $staff->id,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function lockersForStaff(): array
    {
        $statement = $this->pdo->query(
            'SELECT l.id, l.short_name, l.operating_status, l.bookable, l.active, '
            . 'cg.code AS group_code, a.name AS area_name, f.name AS floor_name, bu.name AS building_name, '
            . 'b.id AS booking_id, s.id AS student_id, s.first_name, s.last_name, s.class_name '
            . 'FROM lockers l INNER JOIN corpuses c ON c.id = l.corpus_id '
            . 'INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings bu ON bu.id = f.building_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.locker_id = l.id '
            . 'LEFT JOIN bookings b ON b.id = lo.booking_id '
            . "AND b.status IN ('active', 'payment_due', 'exemption_review') "
            . 'AND CURRENT_DATE BETWEEN b.valid_from AND b.valid_until '
            . 'LEFT JOIN students s ON s.id = b.student_id '
            . 'WHERE l.active = 1 '
            . 'ORDER BY bu.name, f.sort_order, a.name, cg.code, c.position_no, l.position_no LIMIT 1000'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schließfachliste konnte nicht geladen werden.');
        }

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    public function studentByAccessCode(string $matrikelnummer, string $accessCodeHash): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, matrikelnummer, first_name, last_name, class_name, grade, email, access_code_hash '
            . 'FROM students WHERE matrikelnummer = :matrikelnummer AND active = 1 LIMIT 1'
        );
        $statement->execute(['matrikelnummer' => trim($matrikelnummer)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['access_code_hash']) || $row['access_code_hash'] === '') {
            throw new DomainException('Matrikelnummer oder Zugangscode ist ungültig.');
        }
        if (!hash_equals($row['access_code_hash'], $accessCodeHash)) {
            throw new DomainException('Matrikelnummer oder Zugangscode ist ungültig.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function student(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, matrikelnummer, first_name, last_name, class_name, grade, email '
            . 'FROM students WHERE id = :id AND active = 1'
        );
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Der Schülerzugang ist nicht mehr gültig.');
        }

        return $row;
    }

    private function createIncident(
        int $lockerId,
        ?int $bookingId,
        ?int $studentId,
        LockerIncidentCategory $category,
        string $description,
        string $reportedByType,
        ?int $reportedById,
        ?string $reporterEmail,
        ?string $reporterName,
    ): int {
        $description = $this->note($description, 4000);
        if (mb_strlen($description) < 5) {
            throw new DomainException('Bitte das Problem etwas genauer beschreiben.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO locker_incidents '
                . '(locker_id, booking_id, student_id, category, status, priority, description, reported_by_type, '
                . 'reported_by_id, reporter_email, reporter_name, opened_at, created_at, updated_at) VALUES '
                . "(:locker_id, :booking_id, :student_id, :category, 'open', :priority, :description, :reported_by_type, "
                . ':reported_by_id, :reporter_email, :reporter_name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'locker_id' => $lockerId,
                'booking_id' => $bookingId,
                'student_id' => $studentId,
                'category' => $category->value,
                'priority' => $category->priority(),
                'description' => $description,
                'reported_by_type' => $reportedByType,
                'reported_by_id' => $reportedById,
                'reporter_email' => $this->nullable($reporterEmail),
                'reporter_name' => $this->nullable($reporterName),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Der Schließfachvorgang konnte nicht angelegt werden.');
            }
            $this->insertIncidentEvent(
                $id,
                'reported',
                null,
                LockerIncidentStatus::Open->value,
                $description,
                $reportedByType,
                $reportedById,
            );
            if ($category->blocksFutureBookings()) {
                $this->markDefectiveIfOperational($lockerId, $id, $reportedByType, $reportedById);
            }
            $this->pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function assignmentForParent(int $parentId, int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS booking_id, b.student_id, l.id AS locker_id, l.short_name AS locker_name, '
            . 's.first_name, s.last_name, p.email AS reporter_email '
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id AND s.active = 1 '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = s.id AND psl.parent_contact_id = :parent_id '
            . 'INNER JOIN parent_contacts p ON p.id = psl.parent_contact_id AND p.active = 1 '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'WHERE b.id = :booking_id '
            . "AND b.status IN ('active', 'payment_due', 'exemption_review') "
            . 'AND CURRENT_DATE BETWEEN b.valid_from AND b.valid_until LIMIT 1'
        );
        $statement->execute(['parent_id' => $parentId, 'booking_id' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Für diese Buchung kann keine Meldung abgegeben werden.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function assignmentForStudent(int $studentId, int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id AS booking_id, b.student_id, l.id AS locker_id, l.short_name AS locker_name, '
            . 's.first_name, s.last_name, s.email AS reporter_email '
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id AND s.active = 1 '
            . 'INNER JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'INNER JOIN lockers l ON l.id = lo.locker_id '
            . 'WHERE b.id = :booking_id AND b.student_id = :student_id '
            . "AND b.status IN ('active', 'payment_due', 'exemption_review') "
            . 'AND CURRENT_DATE BETWEEN b.valid_from AND b.valid_until LIMIT 1'
        );
        $statement->execute(['student_id' => $studentId, 'booking_id' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Für diese Buchung kann keine Meldung abgegeben werden.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function lockerWithCurrentAssignment(int $lockerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.short_name, b.id AS booking_id, b.student_id '
            . 'FROM lockers l LEFT JOIN locker_occupancies lo ON lo.locker_id = l.id '
            . 'LEFT JOIN bookings b ON b.id = lo.booking_id '
            . "AND b.status IN ('active', 'payment_due', 'exemption_review') "
            . 'AND CURRENT_DATE BETWEEN b.valid_from AND b.valid_until '
            . 'WHERE l.id = :locker_id AND l.active = 1 ORDER BY b.id DESC LIMIT 1'
        );
        $statement->execute(['locker_id' => $lockerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Das Schließfach wurde nicht gefunden.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function lockIncident(int $incidentId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM locker_incidents WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $incidentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Der Schließfachvorgang wurde nicht gefunden.');
        }

        return $row;
    }

    private function markDefectiveIfOperational(
        int $lockerId,
        int $incidentId,
        string $actorType,
        ?int $actorId,
    ): void {
        $statement = $this->pdo->prepare(
            'SELECT operating_status, bookable FROM lockers WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $lockerId]);
        $locker = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($locker) || (string) $locker['operating_status'] !== LockerOperatingStatus::Operational->value) {
            return;
        }

        $oldBookable = (int) $locker['bookable'] === 1;
        $update = $this->pdo->prepare(
            "UPDATE lockers SET operating_status = 'defective', bookable = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $update->execute(['id' => $lockerId]);
        $this->insertOperationEvent(
            $lockerId,
            $incidentId,
            'incident_auto_block',
            LockerOperatingStatus::Operational->value,
            LockerOperatingStatus::Defective->value,
            $oldBookable,
            false,
            'Automatisch aufgrund einer Defektmeldung für neue Buchungen gesperrt.',
            $actorType,
            $actorId,
        );
    }

    private function insertIncidentEvent(
        int $incidentId,
        string $eventType,
        ?string $oldStatus,
        ?string $newStatus,
        string $note,
        string $actorType,
        ?int $actorId,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO locker_incident_events '
            . '(incident_id, event_type, old_status, new_status, note, actor_type, actor_id, created_at) '
            . 'VALUES (:incident_id, :event_type, :old_status, :new_status, :note, :actor_type, :actor_id, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'incident_id' => $incidentId,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'note' => $note !== '' ? $note : null,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    private function insertOperationEvent(
        int $lockerId,
        ?int $incidentId,
        string $eventType,
        ?string $oldStatus,
        ?string $newStatus,
        ?bool $oldBookable,
        ?bool $newBookable,
        string $note,
        string $actorType,
        ?int $actorId,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO locker_operation_events '
            . '(locker_id, incident_id, event_type, old_operating_status, new_operating_status, old_bookable, '
            . 'new_bookable, note, actor_type, actor_id, created_at) VALUES '
            . '(:locker_id, :incident_id, :event_type, :old_status, :new_status, :old_bookable, '
            . ':new_bookable, :note, :actor_type, :actor_id, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'locker_id' => $lockerId,
            'incident_id' => $incidentId,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_bookable' => $oldBookable === null ? null : ($oldBookable ? 1 : 0),
            'new_bookable' => $newBookable === null ? null : ($newBookable ? 1 : 0),
            'note' => $note !== '' ? $note : null,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);
    }

    private function incidentSelect(): string
    {
        return 'SELECT i.*, l.short_name AS locker_name, l.operating_status, l.bookable, '
            . "COALESCE(CONCAT(s.first_name, ' ', s.last_name), '—') AS student_name, s.class_name, "
            . 'sy.label AS school_year_label, cg.code AS group_code, a.name AS area_name, '
            . 'f.name AS floor_name, bu.name AS building_name '
            . 'FROM locker_incidents i INNER JOIN lockers l ON l.id = i.locker_id '
            . 'INNER JOIN corpuses c ON c.id = l.corpus_id INNER JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'INNER JOIN areas a ON a.id = cg.area_id INNER JOIN floors f ON f.id = a.floor_id '
            . 'INNER JOIN buildings bu ON bu.id = f.building_id '
            . 'LEFT JOIN students s ON s.id = i.student_id LEFT JOIN bookings b ON b.id = i.booking_id '
            . 'LEFT JOIN school_years sy ON sy.id = b.school_year_id';
    }

    private function category(string $value): LockerIncidentCategory
    {
        return LockerIncidentCategory::tryFrom(trim($value))
            ?? throw new DomainException('Die Art der Schließfachmeldung ist ungültig.');
    }

    private function note(string $value, int $maxLength): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new DomainException('Der Text ist zu lang.');
        }

        return $value;
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param mixed $rows
     *  @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }
}
