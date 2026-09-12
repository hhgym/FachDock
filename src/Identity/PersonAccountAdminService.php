<?php

declare(strict_types=1);

namespace FachDock\Identity;

use FachDock\Privacy\AccountLifecycleService;
use PDO;
use RuntimeException;

final class PersonAccountAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AccountLifecycleService $lifecycle,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function studentData(string $search = '', string $status = ''): array
    {
        $params = [];
        $where = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(s.matrikelnummer LIKE :search_matrikel OR s.first_name LIKE :search_first_name '
                . 'OR s.last_name LIKE :search_last_name OR s.class_name LIKE :search_class '
                . 'OR s.email LIKE :search_email)';
            $term = '%' . $search . '%';
            $params = [
                'search_matrikel' => $term,
                'search_first_name' => $term,
                'search_last_name' => $term,
                'search_class' => $term,
                'search_email' => $term,
            ];
        }
        if ($status === 'active') {
            $where[] = 's.active = 1 AND s.anonymized_at IS NULL';
        } elseif ($status === 'inactive') {
            $where[] = 's.active = 0 AND s.anonymized_at IS NULL';
        } elseif ($status === 'anonymized') {
            $where[] = 's.anonymized_at IS NOT NULL';
        }

        $sql = 'SELECT s.id, s.matrikelnummer, s.first_name, s.last_name, s.class_name, s.grade, s.email, '
            . 's.active, s.inactive_since, s.account_deactivated_at, s.account_deactivation_source, s.anonymized_at, '
            . 's.created_at, s.updated_at, (s.access_code_hash IS NOT NULL) AS has_access_code, '
            . '(SELECT COUNT(*) FROM oidc_identities oi WHERE oi.student_id = s.id) AS oidc_count, '
            . '(SELECT MAX(oi.last_login_at) FROM oidc_identities oi WHERE oi.student_id = s.id) AS last_oidc_login_at, '
            . '(SELECT COUNT(*) FROM parent_student_link_slots psl WHERE psl.student_id = s.id) AS active_parent_links '
            . 'FROM students s';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.anonymized_at IS NOT NULL, s.active DESC, s.class_name, s.last_name, s.first_name LIMIT 1500';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function studentAccounts(string $search = '', string $status = '', ?int $studentId = null): array
    {
        $settings = $this->lifecycle->settings();
        $params = [];
        $where = ['((s.access_code_hash IS NOT NULL) OR EXISTS (SELECT 1 FROM oidc_identities ox WHERE ox.student_id = s.id))'];
        if ($studentId !== null && $studentId > 0) {
            $where[] = 's.id = :student_id';
            $params['student_id'] = $studentId;
        }
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(s.matrikelnummer LIKE :search_matrikel OR s.first_name LIKE :search_first_name '
                . 'OR s.last_name LIKE :search_last_name OR s.class_name LIKE :search_class)';
            $term = '%' . $search . '%';
            $params['search_matrikel'] = $term;
            $params['search_first_name'] = $term;
            $params['search_last_name'] = $term;
            $params['search_class'] = $term;
        }
        $this->appendStudentAccountStatus($where, $status);

        $deactivationDays = $settings['student_deactivation_days'];
        $anonymizationDays = $settings['student_anonymization_days'];
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.matrikelnummer, s.first_name, s.last_name, s.class_name, s.active, s.inactive_since, '
            . 's.account_deactivated_at, s.account_deactivation_source, s.anonymized_at, '
            . '(s.access_code_hash IS NOT NULL) AS has_access_code, '
            . '(SELECT COUNT(*) FROM oidc_identities oi WHERE oi.student_id = s.id) AS oidc_count, '
            . '(SELECT SUM(oi.active = 1) FROM oidc_identities oi WHERE oi.student_id = s.id) AS active_oidc_count, '
            . '(SELECT MAX(oi.last_login_at) FROM oidc_identities oi WHERE oi.student_id = s.id) AS last_login_at, '
            . 'CASE WHEN s.inactive_since IS NULL THEN NULL ELSE DATE_ADD(s.inactive_since, INTERVAL ' . $deactivationDays . ' DAY) END AS deactivation_due_at, '
            . 'CASE WHEN s.inactive_since IS NULL THEN NULL ELSE DATE_ADD(s.inactive_since, INTERVAL ' . $anonymizationDays . ' DAY) END AS anonymization_due_at '
            . 'FROM students s WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY s.anonymized_at IS NOT NULL, s.account_deactivated_at IS NOT NULL, s.active DESC, s.class_name, s.last_name, s.first_name LIMIT 1500'
        );
        $statement->execute($params);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function parentAccounts(string $search = '', string $status = ''): array
    {
        $settings = $this->lifecycle->settings();
        $params = [];
        $where = [];
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(pc.email LIKE :search_email OR pc.first_name LIKE :search_first_name '
                . 'OR pc.last_name LIKE :search_last_name)';
            $term = '%' . $search . '%';
            $params = [
                'search_email' => $term,
                'search_first_name' => $term,
                'search_last_name' => $term,
            ];
        }
        if ($status === 'active') {
            $where[] = "pc.active = 1 AND pc.status <> 'anonymized' AND pc.anonymized_at IS NULL";
        } elseif ($status === 'deactivated') {
            $where[] = "pc.active = 0 AND pc.status <> 'anonymized' AND pc.anonymized_at IS NULL";
        } elseif ($status === 'anonymized') {
            $where[] = "(pc.status = 'anonymized' OR pc.anonymized_at IS NOT NULL)";
        } elseif ($status === 'waiting') {
            $where[] = "pc.active = 1 AND pc.lifecycle_started_at IS NOT NULL AND pc.status <> 'anonymized'";
        }

        $deactivationDays = $settings['parent_deactivation_days'];
        $anonymizationDays = $settings['parent_anonymization_days'];
        $sql = 'SELECT pc.id, pc.email, pc.first_name, pc.last_name, pc.status, pc.verified_at, pc.active, '
            . 'pc.lifecycle_started_at, pc.deactivated_at, pc.deactivation_source, pc.anonymized_at, '
            . '(SELECT COUNT(*) FROM parent_student_link_slots psl INNER JOIN students s ON s.id = psl.student_id '
            . 'WHERE psl.parent_contact_id = pc.id AND s.active = 1 AND s.anonymized_at IS NULL) AS active_children, '
            . '(SELECT COUNT(*) FROM parent_student_links psl WHERE psl.parent_contact_id = pc.id) AS linked_students_total, '
            . '(SELECT MAX(ps.created_at) FROM parent_sessions ps WHERE ps.parent_contact_id = pc.id) AS last_login_at, '
            . 'CASE WHEN pc.lifecycle_started_at IS NULL THEN NULL ELSE DATE_ADD(pc.lifecycle_started_at, INTERVAL ' . $deactivationDays . ' DAY) END AS deactivation_due_at, '
            . 'CASE WHEN pc.lifecycle_started_at IS NULL THEN NULL ELSE DATE_ADD(pc.lifecycle_started_at, INTERVAL ' . $anonymizationDays . ' DAY) END AS anonymization_due_at '
            . 'FROM parent_contacts pc';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pc.anonymized_at IS NOT NULL, pc.active DESC, pc.last_name, pc.first_name, pc.email LIMIT 1500';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $this->rows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{students:int,student_accounts:int,parents:int,active_students:int,inactive_students:int} */
    public function counts(): array
    {
        $student = $this->pdo->query(
            'SELECT COUNT(*) total, SUM(active = 1 AND anonymized_at IS NULL) active_count, '
            . 'SUM(active = 0 AND anonymized_at IS NULL) inactive_count, '
            . 'SUM((access_code_hash IS NOT NULL) OR EXISTS (SELECT 1 FROM oidc_identities oi WHERE oi.student_id = students.id)) account_count '
            . 'FROM students'
        );
        $parent = $this->pdo->query('SELECT COUNT(*) FROM parent_contacts');
        if ($student === false || $parent === false) {
            throw new RuntimeException('Die Personenstatistik konnte nicht geladen werden.');
        }
        $row = $student->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Die Schülerstatistik konnte nicht geladen werden.');
        }

        return [
            'students' => (int) ($row['total'] ?? 0),
            'student_accounts' => (int) ($row['account_count'] ?? 0),
            'parents' => (int) $parent->fetchColumn(),
            'active_students' => (int) ($row['active_count'] ?? 0),
            'inactive_students' => (int) ($row['inactive_count'] ?? 0),
        ];
    }

    /** @param list<string> $where */
    private function appendStudentAccountStatus(array &$where, string $status): void
    {
        if ($status === 'active') {
            $where[] = 's.account_deactivated_at IS NULL AND s.anonymized_at IS NULL';
        } elseif ($status === 'waiting') {
            $where[] = 's.active = 0 AND s.account_deactivated_at IS NULL AND s.anonymized_at IS NULL';
        } elseif ($status === 'deactivated') {
            $where[] = 's.account_deactivated_at IS NOT NULL AND s.anonymized_at IS NULL';
        } elseif ($status === 'anonymized') {
            $where[] = 's.anonymized_at IS NOT NULL';
        }
    }

    /**
     * @param array<array-key,mixed> $rows
     * @return list<array<string,mixed>>
     */
    private function rows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string,mixed> $row */
            $result[] = $row;
        }

        return $result;
    }
}
