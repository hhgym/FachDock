<?php

declare(strict_types=1);

namespace FachDock\Auth;

use PDO;
use RuntimeException;

final class StaffSessionService
{
    private const SESSION_KEY = 'staff_auth_token';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxLifetimeMinutes = 480,
        private readonly int $idleTimeoutMinutes = 60,
    ) {
    }

    public function create(int $staffUserId, string $ipAddress, string $userAgent): AuthenticatedStaff
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $lifetime = max(1, $this->maxLifetimeMinutes);

        $statement = $this->pdo->prepare(
            'INSERT INTO staff_sessions '
            . '(staff_user_id, token_hash, ip_address, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:user_id, :token_hash, :ip_address, :user_agent, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '
            . 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $lifetime . ' MINUTE))'
        );
        $statement->execute([
            'user_id' => $staffUserId,
            'token_hash' => $tokenHash,
            'ip_address' => $ipAddress !== '' ? $ipAddress : null,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 500) : null,
        ]);

        $_SESSION[self::SESSION_KEY] = $token;
        session_regenerate_id(true);

        $user = $this->current();
        if ($user === null) {
            throw new RuntimeException('Die Sitzung konnte nicht erstellt werden.');
        }

        return $user;
    }

    public function current(): ?AuthenticatedStaff
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        $idle = max(1, $this->idleTimeoutMinutes);
        $statement = $this->pdo->prepare(
            'SELECT s.id AS session_id, u.id, u.username, u.display_name, u.email, u.role '
            . 'FROM staff_sessions s '
            . 'INNER JOIN staff_users u ON u.id = s.staff_user_id '
            . 'WHERE s.token_hash = :token_hash '
            . 'AND s.revoked_at IS NULL '
            . 'AND s.expires_at > CURRENT_TIMESTAMP '
            . 'AND s.last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $idle . ' MINUTE) '
            . 'AND u.active = 1 '
            . 'LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            unset($_SESSION[self::SESSION_KEY]);

            return null;
        }

        $touch = $this->pdo->prepare('UPDATE staff_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id');
        $touch->execute(['id' => (int) $row['session_id']]);

        return new AuthenticatedStaff(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['display_name'],
            (string) $row['email'],
            StaffRole::fromDatabase((string) $row['role']),
            (int) $row['session_id'],
        );
    }

    public function logout(): void
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($token) && $token !== '') {
            $statement = $this->pdo->prepare(
                'UPDATE staff_sessions SET revoked_at = CURRENT_TIMESTAMP '
                . 'WHERE token_hash = :token_hash AND revoked_at IS NULL'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
        }

        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public function revokeAllForUser(int $staffUserId, ?int $exceptSessionId = null): void
    {
        $sql = 'UPDATE staff_sessions SET revoked_at = CURRENT_TIMESTAMP '
            . 'WHERE staff_user_id = :user_id AND revoked_at IS NULL';
        $params = ['user_id' => $staffUserId];

        if ($exceptSessionId !== null) {
            $sql .= ' AND id <> :except_id';
            $params['except_id'] = $exceptSessionId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    /** @return list<array<string, mixed>> */
    public function activeSessionsForUser(int $staffUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, ip_address, user_agent, created_at, last_seen_at, expires_at '
            . 'FROM staff_sessions '
            . 'WHERE staff_user_id = :user_id AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP '
            . 'ORDER BY last_seen_at DESC'
        );
        $statement->execute(['user_id' => $staffUserId]);
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function revokeSession(int $staffUserId, int $sessionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE staff_sessions SET revoked_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :session_id AND staff_user_id = :user_id AND revoked_at IS NULL'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'user_id' => $staffUserId,
        ]);

        $current = $this->current();
        if ($current !== null && $current->sessionId === $sessionId) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }
}
