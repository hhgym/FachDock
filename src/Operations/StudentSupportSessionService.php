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
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $matrikelnummer, string $accessCode): array
    {
        $student = $this->support->studentByAccessCode(
            trim($matrikelnummer),
            $this->codes->hash($accessCode),
        );

        return $this->establish($student);
    }

    /** @return array<string, mixed> */
    public function createForStudentId(int $studentId): array
    {
        return $this->establish($this->support->student($studentId));
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

        try {
            return $this->support->student($studentId);
        } catch (\Throwable) {
            $this->logout();

            return null;
        }
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[OidcSessionService::SESSION_KEY], $_SESSION['oidc_flow']);
    }

    /** @param array<string, mixed> $student
     *  @return array<string, mixed>
     */
    private function establish(array $student): array
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'student_id' => (int) $student['id'],
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ];

        return $student;
    }
}
