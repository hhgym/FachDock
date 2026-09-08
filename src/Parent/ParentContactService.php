<?php

declare(strict_types=1);

namespace FachDock\Parent;

use DomainException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ParentContactService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function linkStudentToEmail(
        string $email,
        int $studentId,
        ?string $firstName,
        ?string $lastName,
        int $staffUserId,
    ): int {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($studentId < 1 || $staffUserId < 1) {
            throw new DomainException('Schüler und bearbeitender Benutzer müssen gültig sein.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertActiveStudent($studentId);
            $parentId = $this->findOrCreateContact(
                $normalizedEmail,
                $this->nullableName($firstName),
                $this->nullableName($lastName),
            );
            $this->assertNoActiveLink($parentId, $studentId);

            $statement = $this->pdo->prepare(
                'INSERT INTO parent_student_links '
                . '(parent_contact_id, student_id, link_origin, started_at, created_by_staff_user_id, created_at) '
                . "VALUES (:parent_contact_id, :student_id, 'staff', CURRENT_TIMESTAMP, :staff_user_id, CURRENT_TIMESTAMP)"
            );
            $statement->execute([
                'parent_contact_id' => $parentId,
                'student_id' => $studentId,
                'staff_user_id' => $staffUserId,
            ]);
            $linkId = (int) $this->pdo->lastInsertId();
            if ($linkId < 1) {
                throw new RuntimeException('Die Eltern-Kind-Verknüpfung konnte nicht angelegt werden.');
            }

            $slot = $this->pdo->prepare(
                'INSERT INTO parent_student_link_slots '
                . '(parent_contact_id, student_id, link_id, created_at) '
                . 'VALUES (:parent_contact_id, :student_id, :link_id, CURRENT_TIMESTAMP)'
            );
            $slot->execute([
                'parent_contact_id' => $parentId,
                'student_id' => $studentId,
                'link_id' => $linkId,
            ]);

            $this->pdo->prepare(
                'UPDATE parent_contacts SET active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['id' => $parentId]);
            $this->pdo->commit();

            return $linkId;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException('Diese Eltern-Kind-Verknüpfung ist bereits aktiv.', 0, $exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deactivateLink(int $linkId, int $staffUserId, string $reason): void
    {
        $reason = trim($reason);
        if ($linkId < 1 || $staffUserId < 1) {
            throw new DomainException('Verknüpfung und bearbeitender Benutzer müssen gültig sein.');
        }
        if ($reason === '') {
            throw new DomainException('Für das Entfernen der Verknüpfung ist eine Begründung erforderlich.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT parent_contact_id, student_id, ended_at FROM parent_student_links WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $linkId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new DomainException('Die Eltern-Kind-Verknüpfung existiert nicht.');
            }
            if ($row['ended_at'] !== null) {
                throw new DomainException('Die Eltern-Kind-Verknüpfung ist bereits beendet.');
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM parent_student_link_slots '
                . 'WHERE link_id = :link_id AND parent_contact_id = :parent_contact_id AND student_id = :student_id'
            );
            $delete->execute([
                'link_id' => $linkId,
                'parent_contact_id' => (int) $row['parent_contact_id'],
                'student_id' => (int) $row['student_id'],
            ]);
            if ($delete->rowCount() !== 1) {
                throw new DomainException('Die aktive Eltern-Kind-Verknüpfung konnte nicht eindeutig aufgelöst werden.');
            }

            $update = $this->pdo->prepare(
                'UPDATE parent_student_links SET ended_at = CURRENT_TIMESTAMP, '
                . 'ended_by_staff_user_id = :staff_user_id, end_reason = :reason WHERE id = :id'
            );
            $update->execute([
                'id' => $linkId,
                'staff_user_id' => $staffUserId,
                'reason' => mb_substr($reason, 0, 255),
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return list<array{
     *     id: int,
     *     email: string,
     *     first_name: string|null,
     *     last_name: string|null,
     *     status: string,
     *     verified_at: string|null,
     *     active: bool,
     *     active_link_count: int
     * }>
     */
    public function allContacts(): array
    {
        $statement = $this->pdo->query(
            'SELECT pc.id, pc.email, pc.first_name, pc.last_name, pc.status, pc.verified_at, pc.active, '
            . 'COUNT(psls.link_id) AS active_link_count '
            . 'FROM parent_contacts pc '
            . 'LEFT JOIN parent_student_link_slots psls ON psls.parent_contact_id = pc.id '
            . 'GROUP BY pc.id, pc.email, pc.first_name, pc.last_name, pc.status, pc.verified_at, pc.active '
            . 'ORDER BY pc.email'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Elternkontakte konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'first_name' => $row['first_name'] !== null ? (string) $row['first_name'] : null,
                'last_name' => $row['last_name'] !== null ? (string) $row['last_name'] : null,
                'status' => (string) $row['status'],
                'verified_at' => $row['verified_at'] !== null ? (string) $row['verified_at'] : null,
                'active' => (int) $row['active'] === 1,
                'active_link_count' => (int) $row['active_link_count'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *     link_id: int,
     *     parent_contact_id: int,
     *     email: string,
     *     student_id: int,
     *     first_name: string,
     *     last_name: string,
     *     class_name: string,
     *     grade: int,
     *     started_at: string
     * }>
     */
    public function activeLinks(): array
    {
        $statement = $this->pdo->query(
            'SELECT psl.id AS link_id, psl.parent_contact_id, pc.email, psl.student_id, '
            . 's.first_name, s.last_name, s.class_name, s.grade, psl.started_at '
            . 'FROM parent_student_link_slots slot '
            . 'INNER JOIN parent_student_links psl ON psl.id = slot.link_id '
            . 'INNER JOIN parent_contacts pc ON pc.id = psl.parent_contact_id '
            . 'INNER JOIN students s ON s.id = psl.student_id '
            . 'ORDER BY pc.email, s.last_name, s.first_name'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Eltern-Kind-Verknüpfungen konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'link_id' => (int) $row['link_id'],
                'parent_contact_id' => (int) $row['parent_contact_id'],
                'email' => (string) $row['email'],
                'student_id' => (int) $row['student_id'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'class_name' => (string) $row['class_name'],
                'grade' => (int) $row['grade'],
                'started_at' => (string) $row['started_at'],
            ];
        }

        return $result;
    }

    /** @return list<array{id: int, first_name: string, last_name: string, class_name: string, grade: int}> */
    public function activeStudents(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, first_name, last_name, class_name, grade FROM students '
            . 'WHERE active = 1 ORDER BY grade, class_name, last_name, first_name, id'
        );
        if ($statement === false) {
            throw new RuntimeException('Die Schüler konnten nicht geladen werden.');
        }

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'class_name' => (string) $row['class_name'],
                'grade' => (int) $row['grade'],
            ];
        }

        return $result;
    }

    private function findOrCreateContact(string $email, ?string $firstName, ?string $lastName): int
    {
        $lookup = $this->pdo->prepare('SELECT id, first_name, last_name FROM parent_contacts WHERE email = :email FOR UPDATE');
        $lookup->execute(['email' => $email]);
        $row = $lookup->fetch();
        if (is_array($row)) {
            $parentId = (int) $row['id'];
            $update = $this->pdo->prepare(
                'UPDATE parent_contacts SET first_name = :first_name, last_name = :last_name, '
                . 'active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $update->execute([
                'id' => $parentId,
                'first_name' => $firstName ?? ($row['first_name'] !== null ? (string) $row['first_name'] : null),
                'last_name' => $lastName ?? ($row['last_name'] !== null ? (string) $row['last_name'] : null),
            ]);

            return $parentId;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO parent_contacts '
            . '(email, first_name, last_name, status, active, created_at, updated_at) '
            . "VALUES (:email, :first_name, :last_name, 'pending', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $insert->execute([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $parentId = (int) $this->pdo->lastInsertId();
        if ($parentId < 1) {
            throw new RuntimeException('Der Elternkontakt konnte nicht angelegt werden.');
        }

        return $parentId;
    }

    private function assertActiveStudent(int $studentId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM students WHERE id = :id AND active = 1 FOR UPDATE');
        $statement->execute(['id' => $studentId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Der Schüler ist nicht aktiv oder existiert nicht.');
        }
    }

    private function assertNoActiveLink(int $parentId, int $studentId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT link_id FROM parent_student_link_slots '
            . 'WHERE parent_contact_id = :parent_contact_id AND student_id = :student_id FOR UPDATE'
        );
        $statement->execute([
            'parent_contact_id' => $parentId,
            'student_id' => $studentId,
        ]);
        if ($statement->fetchColumn() !== false) {
            throw new DomainException('Dieser Elternkontakt ist bereits mit dem Schüler verknüpft.');
        }
    }

    private function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Die E-Mail-Adresse ist ungültig.');
        }

        return $email;
    }

    private function nullableName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 255) {
            throw new DomainException('Der Name ist zu lang.');
        }

        return $value;
    }
}
