<?php

declare(strict_types=1);

namespace FachDock\Operations;

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
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'student_id' => (int) $student['id'],
            'expires_at' => time() + self::LIFETIME_SECONDS,
        ];

        return $student;
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
        unset($_SESSION[self::SESSION_KEY]);
    }
}
