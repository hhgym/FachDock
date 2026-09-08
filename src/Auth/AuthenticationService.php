<?php

declare(strict_types=1);

namespace FachDock\Auth;

use DateTimeImmutable;
use PDO;

final class AuthenticationService
{
    private readonly StaffUserRepository $users;
    private readonly PasswordHasher $hasher;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StaffSessionService $sessions,
        private readonly int $maxFailedAttempts = 5,
        private readonly int $lockoutMinutes = 15,
    ) {
        $this->users = new StaffUserRepository($pdo);
        $this->hasher = new PasswordHasher();
    }

    public function login(string $identifier, string $password, string $ipAddress, string $userAgent): AuthenticatedStaff
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            throw new AuthenticationException('Benutzername oder Passwort ist nicht korrekt.');
        }

        $user = $this->users->findByIdentifier($identifier);
        if ($user === null || (int) $user['active'] !== 1) {
            throw new AuthenticationException('Benutzername oder Passwort ist nicht korrekt.');
        }

        $userId = (int) $user['id'];
        $lockedUntil = isset($user['locked_until']) && is_string($user['locked_until'])
            ? new DateTimeImmutable($user['locked_until'])
            : null;

        if ($lockedUntil !== null && $lockedUntil > new DateTimeImmutable()) {
            throw new AuthenticationException('Die Anmeldung ist vorübergehend gesperrt. Bitte später erneut versuchen.');
        }

        if ($lockedUntil !== null) {
            $this->users->clearFailureState($userId);
        }

        $passwordHash = (string) $user['password_hash'];
        if (!$this->hasher->verify($password, $passwordHash)) {
            $this->users->recordFailedLogin($userId, $this->maxFailedAttempts, $this->lockoutMinutes);
            $this->audit('auth.login.failed', $userId, $ipAddress);

            throw new AuthenticationException('Benutzername oder Passwort ist nicht korrekt.');
        }

        StaffRole::fromDatabase((string) $user['role']);

        if ($this->hasher->needsRehash($passwordHash)) {
            $this->users->replacePasswordHash($userId, $this->hasher->hash($password));
        }

        $this->users->recordSuccessfulLogin($userId);
        $authenticated = $this->sessions->create($userId, $ipAddress, $userAgent);
        $this->audit('auth.login.succeeded', $userId, $ipAddress);

        return $authenticated;
    }

    public function logout(?AuthenticatedStaff $staff, string $ipAddress): void
    {
        $this->sessions->logout();
        if ($staff !== null) {
            $this->audit('auth.logout', $staff->id, $ipAddress);
        }
    }

    private function audit(string $action, int $staffUserId, string $ipAddress): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log '
            . '(actor_type, staff_user_id, action, entity_type, entity_id, metadata, created_at) '
            . 'VALUES (:actor_type, :staff_user_id, :action, :entity_type, :entity_id, :metadata, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'actor_type' => 'staff',
            'staff_user_id' => $staffUserId,
            'action' => $action,
            'entity_type' => 'staff_user',
            'entity_id' => (string) $staffUserId,
            'metadata' => json_encode(['ip_address' => $ipAddress], JSON_THROW_ON_ERROR),
        ]);
    }
}
