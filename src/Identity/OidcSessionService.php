<?php

declare(strict_types=1);

namespace FachDock\Identity;

use DomainException;
use PDO;
use RuntimeException;

final class OidcSessionService
{
    public const SESSION_KEY = 'oidc_auth_token';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxLifetimeMinutes = 480,
        private readonly int $idleTimeoutMinutes = 60,
        private readonly bool $enabled = true,
    ) {
    }

    public function create(int $identityId, string $ipAddress, string $userAgent): AuthenticatedOidcIdentity
    {
        if (!$this->enabled) {
            throw new RuntimeException('Die OpenID-Connect-Anmeldung ist nicht aktiviert.');
        }
        $token = bin2hex(random_bytes(32));
        $lifetime = max(15, $this->maxLifetimeMinutes);
        $statement = $this->pdo->prepare(
            'INSERT INTO oidc_sessions '
            . '(identity_id, token_hash, ip_address, user_agent, created_at, last_seen_at, expires_at) '
            . 'VALUES (:identity_id, :token_hash, :ip_address, :user_agent, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '
            . 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $lifetime . ' MINUTE))'
        );
        $statement->execute([
            'identity_id' => $identityId,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $ipAddress !== '' ? $ipAddress : null,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 500) : null,
        ]);
        $_SESSION[self::SESSION_KEY] = $token;
        session_regenerate_id(true);

        $identity = $this->current();
        if ($identity === null) {
            throw new RuntimeException('Die OpenID-Connect-Sitzung konnte nicht erstellt werden.');
        }

        return $identity;
    }

    public function current(): ?AuthenticatedOidcIdentity
    {
        if (!$this->enabled) {
            $this->logout();

            return null;
        }
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $idle = max(5, $this->idleTimeoutMinutes);
        $statement = $this->pdo->prepare(
            'SELECT s.id AS session_id, i.id, i.issuer, i.subject, i.identity_type, i.student_id, '
            . 'i.account_name, i.display_name, i.email '
            . 'FROM oidc_sessions s INNER JOIN oidc_identities i ON i.id = s.identity_id '
            . 'LEFT JOIN students st ON st.id = i.student_id '
            . 'WHERE s.token_hash = :token_hash AND s.revoked_at IS NULL '
            . 'AND s.expires_at > CURRENT_TIMESTAMP '
            . 'AND s.last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $idle . ' MINUTE) '
            . 'AND i.active = 1 '
            . "AND i.identity_type IN ('student','teacher') "
            . "AND (i.identity_type <> 'student' OR (st.id IS NOT NULL AND st.account_deactivated_at IS NULL AND st.anonymized_at IS NULL)) "
            . 'LIMIT 1'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $this->logout();

            return null;
        }
        $touch = $this->pdo->prepare('UPDATE oidc_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id');
        $touch->execute(['id' => (int) $row['session_id']]);

        return new AuthenticatedOidcIdentity(
            (int) $row['id'],
            (string) $row['issuer'],
            (string) $row['subject'],
            (string) $row['identity_type'],
            $row['student_id'] === null ? null : (int) $row['student_id'],
            $row['account_name'] === null ? null : (string) $row['account_name'],
            $row['display_name'] === null ? null : (string) $row['display_name'],
            $row['email'] === null ? null : (string) $row['email'],
            (int) $row['session_id'],
        );
    }

    /** @return array<string,mixed> */
    public function student(int $studentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, matrikelnummer, first_name, last_name, class_name, grade, email '
            . 'FROM students WHERE id = :id AND account_deactivated_at IS NULL AND anonymized_at IS NULL'
        );
        $statement->execute(['id' => $studentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('Der Schülerzugang ist nicht mehr gültig.');
        }

        return $row;
    }

    public function logout(): void
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($token) && $token !== '') {
            $statement = $this->pdo->prepare(
                'UPDATE oidc_sessions SET revoked_at = CURRENT_TIMESTAMP '
                . 'WHERE token_hash = :token_hash AND revoked_at IS NULL'
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
        }
        unset($_SESSION[self::SESSION_KEY], $_SESSION['oidc_flow']);
        session_regenerate_id(true);
    }
}
