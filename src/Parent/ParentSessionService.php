<?php

declare(strict_types=1);

namespace FachDock\Parent;

use PDO;
use RuntimeException;

final class ParentSessionService
{
    private const SESSION_KEY = 'parent_auth_token';
    private const ADMIN_PREVIEW_KEY = 'parent_admin_preview';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxLifetimeMinutes = 1440,
        private readonly int $staffIdleTimeoutMinutes = 60,
    ) {
    }

    public function create(int $parentContactId, string $ipAddress, string $userAgent): AuthenticatedParent
    {
        unset($_SESSION[self::ADMIN_PREVIEW_KEY]);

        $token = bin2hex(random_bytes(32));
        $lifetime = max(1, min(10080, $this->maxLifetimeMinutes));
        $statement = $this->pdo->prepare(
            'INSERT INTO parent_sessions '
            . '(parent_contact_id, token_hash, ip_address, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:parent_contact_id, :token_hash, :ip_address, :user_agent, CURRENT_TIMESTAMP, '
            . 'CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $lifetime . ' MINUTE))'
        );
        $statement->execute([
            'parent_contact_id' => $parentContactId,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $this->nullable($ipAddress, 45),
            'user_agent' => $this->nullable($userAgent, 500),
        ]);

        $_SESSION[self::SESSION_KEY] = $token;
        session_regenerate_id(true);
        $parent = $this->current();
        if ($parent === null) {
            throw new RuntimeException('Die Elternsitzung konnte nicht erstellt werden.');
        }

        return $parent;
    }

    public function current(): ?AuthenticatedParent
    {
        $preview = $this->currentAdminPreview();
        if ($preview !== null) {
            return $preview;
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT ps.id AS session_id, pc.id, pc.email, pc.first_name, pc.last_name '
            . 'FROM parent_sessions ps INNER JOIN parent_contacts pc ON pc.id = ps.parent_contact_id '
            . 'WHERE ps.token_hash = :token_hash AND ps.revoked_at IS NULL '
            . 'AND ps.expires_at > CURRENT_TIMESTAMP AND pc.active = 1 AND pc.verified_at IS NOT NULL LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        $this->pdo->prepare('UPDATE parent_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => (int) $row['session_id']]);

        return new AuthenticatedParent(
            (int) $row['id'],
            (string) $row['email'],
            $row['first_name'] === null ? null : (string) $row['first_name'],
            $row['last_name'] === null ? null : (string) $row['last_name'],
            (int) $row['session_id'],
        );
    }

    public function logout(): void
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($token) && $token !== '') {
            $statement = $this->pdo->prepare(
                'UPDATE parent_sessions SET revoked_at = CURRENT_TIMESTAMP '
                . 'WHERE token_hash = :token_hash AND revoked_at IS NULL'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
        }
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::ADMIN_PREVIEW_KEY]);
        session_regenerate_id(true);
    }

    public function revokeAllForParent(int $parentContactId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE parent_sessions SET revoked_at = CURRENT_TIMESTAMP '
            . 'WHERE parent_contact_id = :parent_contact_id AND revoked_at IS NULL'
        );
        $statement->execute(['parent_contact_id' => $parentContactId]);
    }

    private function currentAdminPreview(): ?AuthenticatedParent
    {
        $preview = $_SESSION[self::ADMIN_PREVIEW_KEY] ?? null;
        if (!is_array($preview)) {
            return null;
        }

        $parentContactId = $preview['parent_contact_id'] ?? null;
        $staffUserId = $preview['staff_user_id'] ?? null;
        $staffSessionId = $preview['staff_session_id'] ?? null;
        $staffTokenHash = $preview['staff_token_hash'] ?? null;
        $expiresAt = $preview['expires_at'] ?? null;
        $staffToken = $_SESSION['staff_auth_token'] ?? null;

        if (!is_int($parentContactId) || $parentContactId < 1
            || !is_int($staffUserId) || $staffUserId < 1
            || !is_int($staffSessionId) || $staffSessionId < 1
            || !is_string($staffTokenHash) || $staffTokenHash === ''
            || !is_int($expiresAt) || $expiresAt <= time()
            || !is_string($staffToken) || $staffToken === ''
            || !hash_equals($staffTokenHash, hash('sha256', $staffToken))) {
            unset($_SESSION[self::ADMIN_PREVIEW_KEY]);
            return null;
        }

        $idle = max(1, $this->staffIdleTimeoutMinutes);
        $statement = $this->pdo->prepare(
            'SELECT pc.id, pc.email, pc.first_name, pc.last_name '
            . 'FROM parent_contacts pc '
            . 'INNER JOIN staff_sessions ss ON ss.id = :staff_session_id '
            . 'INNER JOIN staff_users su ON su.id = ss.staff_user_id '
            . 'WHERE pc.id = :parent_contact_id AND pc.active = 1 '
            . 'AND ss.staff_user_id = :staff_user_id AND ss.token_hash = :staff_token_hash '
            . 'AND ss.revoked_at IS NULL AND ss.expires_at > CURRENT_TIMESTAMP '
            . 'AND ss.last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $idle . ' MINUTE) '
            . "AND su.active = 1 AND su.role = 'administrator' LIMIT 1"
        );
        $statement->execute([
            'staff_session_id' => $staffSessionId,
            'parent_contact_id' => $parentContactId,
            'staff_user_id' => $staffUserId,
            'staff_token_hash' => $staffTokenHash,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            unset($_SESSION[self::ADMIN_PREVIEW_KEY]);
            return null;
        }

        return new AuthenticatedParent(
            (int) $row['id'],
            (string) $row['email'],
            $row['first_name'] === null ? null : (string) $row['first_name'],
            $row['last_name'] === null ? null : (string) $row['last_name'],
            0,
            true,
            $staffUserId,
        );
    }

    private function nullable(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
