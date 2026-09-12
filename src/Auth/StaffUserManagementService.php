<?php

declare(strict_types=1);

namespace FachDock\Auth;

use DomainException;
use PDO;
use PDOException;
use RuntimeException;

final class StaffUserManagementService
{
    private readonly PasswordHasher $hasher;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StaffSessionService $sessions,
        private readonly int $minimumPasswordLength = 12,
    ) {
        $this->hasher = new PasswordHasher();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, username, display_name, email, role, active, deactivated_at, anonymized_at, '
            . 'last_login_at, created_at, updated_at '
            . 'FROM staff_users ORDER BY anonymized_at IS NOT NULL, active DESC, display_name, username'
        );
        if ($statement === false) {
            throw new RuntimeException('Die lokalen Benutzer konnten nicht geladen werden.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function create(
        string $username,
        string $displayName,
        string $email,
        string $role,
        string $password,
        string $confirmation,
    ): int {
        $username = trim($username);
        $displayName = trim($displayName);
        $email = mb_strtolower(trim($email));
        $staffRole = StaffRole::tryFrom($role);

        if (!preg_match('/^[^\s]{3,100}$/u', $username)) {
            throw new DomainException('Der Benutzername muss 3 bis 100 Zeichen lang sein und darf keine Leerzeichen enthalten.');
        }
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new DomainException('Der Anzeigename darf nicht leer sein und höchstens 255 Zeichen enthalten.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            throw new DomainException('Die E-Mail-Adresse ist ungültig.');
        }
        if ($staffRole === null) {
            throw new DomainException('Die ausgewählte Rolle ist ungültig.');
        }
        if (mb_strlen($password) < max(1, $this->minimumPasswordLength)) {
            throw new DomainException(
                'Das Passwort muss mindestens ' . max(1, $this->minimumPasswordLength) . ' Zeichen lang sein.'
            );
        }
        if ($password !== $confirmation) {
            throw new DomainException('Die beiden Passwörter stimmen nicht überein.');
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO staff_users '
                . '(username, display_name, email, password_hash, role, active, created_at, updated_at, password_changed_at) '
                . 'VALUES (:username, :display_name, :email, :password_hash, :role, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email,
                'password_hash' => $this->hasher->hash($password),
                'role' => $staffRole->value,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException('Benutzername oder E-Mail-Adresse wird bereits verwendet.', 0, $exception);
            }
            throw $exception;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function deactivate(int $targetId, int $currentUserId): void
    {
        $user = $this->requireUser($targetId);
        $this->ensureNotSelf($targetId, $currentUserId, 'deaktivieren');
        $this->ensureNotAnonymized($user);

        if ((int) $user['active'] !== 1) {
            throw new DomainException('Das Konto ist bereits deaktiviert.');
        }
        $this->ensureAnotherAdministratorRemains($user);

        $statement = $this->pdo->prepare(
            'UPDATE staff_users SET active = 0, deactivated_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $statement->execute(['id' => $targetId]);
        $this->sessions->revokeAllForUser($targetId);
    }

    public function reactivate(int $targetId): void
    {
        $user = $this->requireUser($targetId);
        $this->ensureNotAnonymized($user);

        if ((int) $user['active'] === 1) {
            throw new DomainException('Das Konto ist bereits aktiv.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE staff_users SET active = 1, deactivated_at = NULL, failed_login_attempts = 0, '
            . 'last_failed_login_at = NULL, locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $targetId]);
    }

    public function anonymize(int $targetId, int $currentUserId): void
    {
        $user = $this->requireUser($targetId);
        $this->ensureNotSelf($targetId, $currentUserId, 'anonymisieren');
        $this->ensureNotAnonymized($user);

        if ((int) $user['active'] === 1) {
            throw new DomainException('Das Konto muss vor der Anonymisierung deaktiviert werden.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteAuthenticationArtifacts($targetId);
            $statement = $this->pdo->prepare(
                'UPDATE staff_users SET '
                . 'username = :username, display_name = :display_name, email = :email, password_hash = :password_hash, '
                . 'active = 0, deactivated_at = COALESCE(deactivated_at, CURRENT_TIMESTAMP), anonymized_at = CURRENT_TIMESTAMP, '
                . 'last_login_at = NULL, last_failed_login_at = NULL, failed_login_attempts = 0, locked_until = NULL, '
                . 'password_changed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :id'
            );
            $statement->execute([
                'username' => 'anonymized-' . $targetId,
                'display_name' => 'Anonymisiertes Konto #' . $targetId,
                'email' => 'anonymized-' . $targetId . '@invalid.local',
                'password_hash' => $this->hasher->hash(bin2hex(random_bytes(32))),
                'id' => $targetId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function delete(int $targetId, int $currentUserId): void
    {
        $user = $this->requireUser($targetId);
        $this->ensureNotSelf($targetId, $currentUserId, 'löschen');

        if ((int) $user['active'] === 1) {
            throw new DomainException('Das Konto muss vor dem endgültigen Löschen deaktiviert werden.');
        }
        if ($this->hasPermanentReferences($targetId)) {
            throw new DomainException(
                'Das Konto besitzt revisionsrelevante Historie und kann nicht gelöscht werden. Bitte anonymisieren Sie es stattdessen.'
            );
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteAuthenticationArtifacts($targetId);
            $statement = $this->pdo->prepare('DELETE FROM staff_users WHERE id = :id');
            $statement->execute(['id' => $targetId]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('Das Konto konnte nicht gelöscht werden.');
            }
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new DomainException(
                    'Das Konto wird noch von historischen Datensätzen referenziert. Bitte anonymisieren Sie es stattdessen.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function requireUser(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, email, role, active, anonymized_at FROM staff_users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new DomainException('Das Benutzerkonto existiert nicht.');
        }

        return $user;
    }

    /** @param array<string, mixed> $user */
    private function ensureNotAnonymized(array $user): void
    {
        if ($user['anonymized_at'] !== null) {
            throw new DomainException('Ein anonymisiertes Konto kann nicht mehr reaktiviert oder verändert werden.');
        }
    }

    private function ensureNotSelf(int $targetId, int $currentUserId, string $action): void
    {
        if ($targetId === $currentUserId) {
            throw new DomainException('Das aktuell verwendete eigene Konto kann nicht ' . $action . ' werden.');
        }
    }

    /** @param array<string, mixed> $user */
    private function ensureAnotherAdministratorRemains(array $user): void
    {
        if ((string) $user['role'] !== StaffRole::Administrator->value || (int) $user['active'] !== 1) {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM staff_users '
            . 'WHERE role = :role AND active = 1 AND anonymized_at IS NULL AND id <> :id'
        );
        $statement->execute([
            'role' => StaffRole::Administrator->value,
            'id' => (int) $user['id'],
        ]);
        if ((int) $statement->fetchColumn() < 1) {
            throw new DomainException('Mindestens ein aktives Administratorkonto muss erhalten bleiben.');
        }
    }

    private function deleteAuthenticationArtifacts(int $staffUserId): void
    {
        $reset = $this->pdo->prepare('DELETE FROM staff_password_reset_tokens WHERE staff_user_id = :id');
        $reset->execute(['id' => $staffUserId]);
        $sessions = $this->pdo->prepare('DELETE FROM staff_sessions WHERE staff_user_id = :id');
        $sessions->execute(['id' => $staffUserId]);
    }

    private function hasPermanentReferences(int $staffUserId): bool
    {
        $statement = $this->pdo->query(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
            . "WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'staff_users' "
            . "AND REFERENCED_COLUMN_NAME = 'id' "
            . "AND TABLE_NAME NOT IN ('staff_sessions', 'staff_password_reset_tokens')"
        );
        if ($statement === false) {
            throw new RuntimeException('Referenzen des lokalen Benutzerkontos konnten nicht geprüft werden.');
        }
        $references = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($references as $reference) {
            $table = (string) ($reference['TABLE_NAME'] ?? '');
            $column = (string) ($reference['COLUMN_NAME'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                continue;
            }
            $count = $this->pdo->prepare(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = :id'
            );
            $count->execute(['id' => $staffUserId]);
            if ((int) $count->fetchColumn() > 0) {
                return true;
            }
        }

        return false;
    }
}
