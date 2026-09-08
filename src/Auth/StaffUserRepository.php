<?php

declare(strict_types=1);

namespace FachDock\Auth;

use PDO;

final class StaffUserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByIdentifier(string $identifier): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, email, password_hash, role, active, '
            . 'failed_login_attempts, locked_until '
            . 'FROM staff_users '
            . 'WHERE username = :identifier OR email = :identifier '
            . 'LIMIT 1'
        );
        $statement->execute(['identifier' => $identifier]);

        return $this->fetchRow($statement);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, email, password_hash, role, active, '
            . 'failed_login_attempts, locked_until '
            . 'FROM staff_users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);

        return $this->fetchRow($statement);
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE staff_users SET last_login_at = CURRENT_TIMESTAMP, failed_login_attempts = 0, '
            . 'last_failed_login_at = NULL, locked_until = NULL, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function recordFailedLogin(int $userId, int $maxAttempts, int $lockoutMinutes): void
    {
        $lockoutMinutes = max(1, $lockoutMinutes);
        $statement = $this->pdo->prepare(
            'UPDATE staff_users SET '
            . 'failed_login_attempts = failed_login_attempts + 1, '
            . 'last_failed_login_at = CURRENT_TIMESTAMP, '
            . 'locked_until = CASE '
            . 'WHEN failed_login_attempts + 1 >= :max_attempts '
            . 'THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $lockoutMinutes . ' MINUTE) '
            . 'ELSE locked_until END, '
            . 'updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'max_attempts' => max(1, $maxAttempts),
        ]);
    }

    public function clearFailureState(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE staff_users SET failed_login_attempts = 0, last_failed_login_at = NULL, '
            . 'locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function replacePasswordHash(int $userId, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE staff_users SET password_hash = :hash, password_changed_at = CURRENT_TIMESTAMP, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $userId, 'hash' => $hash]);
    }

    /** @return array<string, mixed>|null */
    private function fetchRow(\PDOStatement $statement): ?array
    {
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
