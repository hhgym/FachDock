<?php

declare(strict_types=1);

namespace FachDock\Operations;

use FachDock\Identity\OidcSessionService;
use FachDock\Student\AccessCodeGenerator;

final class StudentSupportSessionService
{
    private const SESSION_KEY = 'student_support_session';
    private const LIFETIME_SECONDS = 28800;

    public function __construct(
        private readonly LockerSupportService $support,
        private readonly AccessCodeGenerator $codes = new AccessCodeGenerator(),
        private readonly ?OidcSessionService $oidcSessions = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $matrikelnummer, string $accessCode): array
    {
        $student = $this->support->studentByAccessCode(
            trim($matrikelnummer),
            $this->codes->hash($accessCode),
        );

        return $this->establish($student, null);
    }

    /** @return array<string, mixed> */
    public function createForStudentId(int $studentId, ?int $oidcIdentityId = null): array
    {
        return $this->establish($this->support->student($studentId), $oidcIdentityId);
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
            return $this->support->student($studentId);
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
}
