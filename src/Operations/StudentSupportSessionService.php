<?php

declare(strict_types=1);

namespace FachDock\Operations;

use DomainException;
use FachDock\Identity\OidcSessionService;
use FachDock\Student\AccessCodeGenerator;
use PDO;

final class StudentSupportSessionService
{
    private const SESSION_KEY = 'student_support_session';
    private const LIFETIME_SECONDS = 28800;

    public function __construct(
        private readonly LockerSupportService $support,
        private readonly AccessCodeGenerator $codes = new AccessCodeGenerator(),
        private readonly ?OidcSessionService $oidcSessions = null,
        private readonly ?PDO $pdo = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $matrikelnummer, string $accessCode): array
    {
        $student = $this->pdo === null
            ? $this->support->studentByAccessCode(trim($matrikelnummer), $this->codes->hash($accessCode))
            : $this->studentByAccessCode(trim($matrikelnummer), $this->codes->hash($accessCode));

        return $this->establish($student, null);
    }

    /** @return array<string, mixed> */
    public function createForStudentId(int $studentId, ?int $oidcIdentityId = null): array
    {
        $student = $this->pdo === null ? $this->support->student($studentId) : $this->student($studentId);

        return $this->establish($student, $oidcIdentityId);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($session)) {
            return null;
        }
        $studentId = $session['student_id'] ?? null;
        $expiresAt = $session['expires_at'] ?? null;
        if (!is_int($studentId) || $studentId < 1 || !is_int($expiresAt) || $expiresAt < time()) {
            $this->logout();

            return null;
        }

        $oidcIdentityId = $session['oidc_identity_id'] ?? null;
        if ($oidcIdentityId !== null) {
            if (!is_int($oidcIdentityId) || $oidcIdentityId < 1 || $this->oidcSessions === null) {
                $this->logout();

                return null;
            }
            $identity = $this->oidcSessions->current();
            if ($identity === null || !$identity->isStudent()
                || $identity->id !== $oidcIdentityId || $identity->studentId !== $studentId) {
                $this->logout();

                return null;
            }
        }

        try {
            return $this->pdo === null ? $this->support->student($studentId) : $this->student($studentId);
        } catch (\Throwable) {
            $this->logout();

            return null;
        }
    }

    public function logout(): void
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;
        $hasOidcIdentity = is_array($session)
            && isset($session['oidc_identity_id'])
            && is_int($session['oidc_identity_id']);
        unset($_SESSION[self::SESSION_KEY]);
        if ($hasOidcIdentity && $this->oidcSessions !== null) {
            $this->oidcSessions->logout();
        }
    }

    /**
     * @param array<string, mixed> $student
     * @return array<string, mixed>
     */
    private function establish(array $student, ?int $oidcIdentityId): array
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'student_id' => (int) $student['id'],
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ];
        if ($oidcIdentityId !== null) {
            $_SESSION[self::SESSION_KEY]['oidc_identity_id'] = $oidcIdentityId;
        }

        return $student;
    }

    /** @return array<string,mixed> */
    private function studentByAccessCode(string $matrikelnummer, string $accessCodeHash): array
    {
        if ($this->pdo === null) {
            throw new DomainException('Der Schülerzugang ist nicht verfügbar.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, matrikelnummer, first_name, last_name, class_name, grade, email, access_code_hash '
            . 'FROM students WHERE matrikelnummer = :matrikelnummer '
            . 'AND account_deactivated_at IS NULL AND anonymized_at IS NULL LIMIT 1'
        );
        $statement->execute(['matrikelnummer' => $matrikelnummer]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['access_code_hash']) || $row['access_code_hash'] === '') {
            throw new DomainException('Matrikelnummer oder Zugangscode ist ungültig.');
        }
        if (!hash_equals($row['access_code_hash'], $accessCodeHash)) {
            throw new DomainException('Matrikelnummer oder Zugangscode ist ungültig.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function student(int $studentId): array
    {
        if ($this->pdo === null) {
            throw new DomainException('Der Schülerzugang ist nicht verfügbar.');
        }
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
}
