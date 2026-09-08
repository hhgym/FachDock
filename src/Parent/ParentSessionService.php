<?php

declare(strict_types=1);

namespace FachDock\Parent;

use PDO;
use RuntimeException;

final class ParentSessionService
{
    private const SESSION_KEY = 'parent_auth_token';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxLifetimeMinutes = 1440,
    ) {
    }

    public function create(int $parentContactId, string $ipAddress, string $userAgent): AuthenticatedParent
    {
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
        unset($_SESSION[self::SESSION_KEY]);
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

    private function nullable(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
