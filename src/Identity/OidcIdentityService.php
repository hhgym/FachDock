<?php

declare(strict_types=1);

namespace FachDock\Identity;

use PDO;
use RuntimeException;

final class OidcIdentityService
{
    private const FLOW_KEY = 'oidc_flow';
    private const FLOW_LIFETIME_SECONDS = 600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly OidcConfiguration $configuration,
        private readonly OidcHttpClient $http,
    ) {
    }

    /** @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:string} */
    public function connectionCheck(): array
    {
        $this->configuration->assertReady();
        $metadata = $this->metadata();

        return [
            'issuer' => (string) $metadata['issuer'],
            'authorization_endpoint' => (string) $metadata['authorization_endpoint'],
            'token_endpoint' => (string) $metadata['token_endpoint'],
            'userinfo_endpoint' => (string) $metadata['userinfo_endpoint'],
        ];
    }

    public function begin(string $area): string
    {
        $this->configuration->assertReady();
        if (!in_array($area, ['student', 'teacher'], true)) {
            throw new RuntimeException('Der gewünschte IServ-Anmeldebereich ist ungültig.');
        }
        $metadata = $this->metadata();
        $state = bin2hex(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(48));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $_SESSION[self::FLOW_KEY] = [
            'state' => $state,
            'verifier' => $verifier,
            'area' => $area,
            'started_at' => time(),
        ];
        session_regenerate_id(true);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->configuration->clientId(),
            'redirect_uri' => $this->configuration->callbackUrl(),
            'scope' => $this->configuration->scopes(),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return (string) $metadata['authorization_endpoint'] . '?' . $query;
    }

    /** @return array{identity:array<string,mixed>,area:string} */
    public function finish(string $code, string $state): array
    {
        $this->configuration->assertReady();
        $flow = $_SESSION[self::FLOW_KEY] ?? null;
        unset($_SESSION[self::FLOW_KEY]);
        if (!is_array($flow)) {
            throw new RuntimeException('Die IServ-Anmeldung ist abgelaufen. Bitte erneut starten.');
        }
        $expectedState = $flow['state'] ?? null;
        $verifier = $flow['verifier'] ?? null;
        $area = $flow['area'] ?? null;
        $startedAt = $flow['started_at'] ?? null;
        if (!is_string($expectedState) || !hash_equals($expectedState, $state)
            || !is_string($verifier) || !is_string($area) || !is_int($startedAt)
            || $startedAt < time() - self::FLOW_LIFETIME_SECONDS) {
            throw new RuntimeException('Die IServ-Anmeldung konnte nicht sicher bestätigt werden.');
        }
        if ($code === '') {
            throw new RuntimeException('IServ hat keinen Autorisierungscode geliefert.');
        }

        $metadata = $this->metadata();
        $token = $this->http->postForm((string) $metadata['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->configuration->callbackUrl(),
            'client_id' => $this->configuration->clientId(),
            'code_verifier' => $verifier,
        ], [
            'Authorization' => 'Basic ' . base64_encode(
                $this->configuration->clientId() . ':' . $this->configuration->clientSecret(),
            ),
        ]);
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('IServ hat kein gültiges Access Token geliefert.');
        }
        $userinfo = $this->http->getJson((string) $metadata['userinfo_endpoint'], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        $subject = $userinfo['sub'] ?? null;
        if (!is_string($subject) || trim($subject) === '') {
            throw new RuntimeException('IServ hat keine OpenID-Subject-ID geliefert.');
        }

        return ['identity' => $this->upsertIdentity($userinfo), 'area' => $area];
    }

    /** @return list<array<string, mixed>> */
    public function identities(): array
    {
        $statement = $this->pdo->query(
            'SELECT i.id, i.issuer, i.subject, i.uuid, i.account_name, i.display_name, i.email, '
            . 'i.identity_type, i.assignment_source, i.student_id, i.active, i.last_login_at, '
            . "CONCAT(st.first_name, ' ', st.last_name) AS student_name, st.matrikelnummer, st.class_name "
            . 'FROM oidc_identities i LEFT JOIN students st ON st.id = i.student_id '
            . 'ORDER BY i.last_login_at DESC, i.id DESC LIMIT 200'
        );
        if ($statement === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function approveTeacher(int $identityId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE oidc_identities SET identity_type = 'teacher', assignment_source = 'manual', student_id = NULL, active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $identityId]);
        $this->assertChanged($statement->rowCount());
    }

    public function linkStudent(int $identityId, string $matrikelnummer): int
    {
        $studentId = $this->uniqueStudentId('matrikelnummer = :value', trim($matrikelnummer));
        if ($studentId === null) {
            throw new RuntimeException('Es wurde kein eindeutiger aktiver Schüler mit dieser Matrikelnummer gefunden.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE oidc_identities SET identity_type = 'student', assignment_source = 'manual', student_id = :student_id, active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['student_id' => $studentId, 'id' => $identityId]);
        $this->assertChanged($statement->rowCount());

        return $studentId;
    }

    public function setPending(int $identityId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE oidc_identities SET identity_type = 'pending', assignment_source = 'manual', student_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $identityId]);
        $this->assertChanged($statement->rowCount());
        $this->revokeSessions($identityId);
    }

    public function useAutomaticAssignment(int $identityId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE oidc_identities SET identity_type = 'pending', assignment_source = 'automatic', student_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $statement->execute(['id' => $identityId]);
        $this->assertChanged($statement->rowCount());
        $this->revokeSessions($identityId);
    }

    public function setActive(int $identityId, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE oidc_identities SET active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $identityId]);
        $this->assertChanged($statement->rowCount());
        if (!$active) {
            $this->revokeSessions($identityId);
        }
    }

    /**
     * @param array<string, mixed> $userinfo
     * @return array<string, mixed>
     */
    private function upsertIdentity(array $userinfo): array
    {
        $issuer = $this->configuration->issuer();
        $subject = trim((string) $userinfo['sub']);
        $existing = $this->identityBySubject($issuer, $subject);
        $uuid = $this->nullableClaim($userinfo, 'uuid', 128);
        $account = $this->nullableClaim($userinfo, 'preferred_username', 190);
        $display = $this->nullableClaim($userinfo, 'name', 255);
        $email = $this->nullableClaim($userinfo, 'email', 255);
        $assignmentSource = is_array($existing)
            ? (string) ($existing['assignment_source'] ?? 'automatic')
            : 'automatic';
        $type = is_array($existing) ? (string) $existing['identity_type'] : 'pending';
        $studentId = is_array($existing) && $existing['student_id'] !== null ? (int) $existing['student_id'] : null;
        if ($assignmentSource === 'automatic') {
            [$type, $studentId] = $this->classify($userinfo, $email, $account);
        }

        if (is_array($existing)) {
            $statement = $this->pdo->prepare(
                'UPDATE oidc_identities SET uuid = :uuid, account_name = :account_name, display_name = :display_name, '
                . 'email = :email, identity_type = :identity_type, student_id = :student_id, '
                . 'last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $statement->execute([
                'uuid' => $uuid,
                'account_name' => $account,
                'display_name' => $display,
                'email' => $email,
                'identity_type' => $type,
                'student_id' => $studentId,
                'id' => (int) $existing['id'],
            ]);
            $id = (int) $existing['id'];
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO oidc_identities '
                . '(issuer, subject, uuid, account_name, display_name, email, identity_type, assignment_source, student_id, active, last_login_at, created_at, updated_at) '
                . "VALUES (:issuer, :subject, :uuid, :account_name, :display_name, :email, :identity_type, 'automatic', :student_id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $statement->execute([
                'issuer' => $issuer,
                'subject' => $subject,
                'uuid' => $uuid,
                'account_name' => $account,
                'display_name' => $display,
                'email' => $email,
                'identity_type' => $type,
                'student_id' => $studentId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        }

        return $this->identity($id);
    }

    /**
     * @param array<string, mixed> $userinfo
     * @return array{0:string,1:?int}
     */
    private function classify(array $userinfo, ?string $email, ?string $account): array
    {
        $studentId = null;
        if ($this->configuration->studentAutoMatch() === 'email' && $email !== null) {
            $studentId = $this->uniqueStudentId('LOWER(email) = LOWER(:value)', $email);
        } elseif ($this->configuration->studentAutoMatch() === 'username_to_matrikelnummer' && $account !== null) {
            $studentId = $this->uniqueStudentId('matrikelnummer = :value', $account);
        }
        if ($studentId !== null) {
            return ['student', $studentId];
        }
        if ($this->hasTeacherRole($userinfo)) {
            return ['teacher', null];
        }

        return ['pending', null];
    }

    /** @param array<string, mixed> $userinfo */
    private function hasTeacherRole(array $userinfo): bool
    {
        $accepted = $this->configuration->teacherRoleNames();
        if ($accepted === []) {
            return false;
        }
        $roles = $userinfo['iserv:roles'] ?? [];
        if (!is_array($roles)) {
            return false;
        }
        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            $name = $role['displayName'] ?? null;
            if (is_string($name) && in_array(mb_strtolower(trim($name)), $accepted, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $issuer = $this->configuration->issuer();
        $metadata = $this->http->getJson($issuer . '/.well-known/openid-configuration');
        if (($metadata['issuer'] ?? null) !== $issuer) {
            throw new RuntimeException('Der OIDC-Issuer der Discovery-Antwort stimmt nicht mit der Konfiguration überein.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            $endpoint = $metadata[$key] ?? null;
            if (!is_string($endpoint) || !$this->sameOriginHttps($issuer, $endpoint)) {
                throw new RuntimeException('Die OIDC-Discovery enthält einen unzulässigen Endpunkt: ' . $key . '.');
            }
        }

        return $metadata;
    }

    private function sameOriginHttps(string $issuer, string $endpoint): bool
    {
        if (!str_starts_with(strtolower($endpoint), 'https://')) {
            return false;
        }

        return strtolower((string) parse_url($issuer, PHP_URL_HOST)) === strtolower((string) parse_url($endpoint, PHP_URL_HOST))
            && (parse_url($issuer, PHP_URL_PORT) ?: 443) === (parse_url($endpoint, PHP_URL_PORT) ?: 443);
    }

    /** @return array<string, mixed>|null */
    private function identityBySubject(string $issuer, string $subject): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM oidc_identities WHERE issuer = :issuer AND subject = :subject LIMIT 1');
        $statement->execute(['issuer' => $issuer, 'subject' => $subject]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function identity(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM oidc_identities WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Die OIDC-Identität konnte nicht geladen werden.');
        }

        return $row;
    }

    private function uniqueStudentId(string $condition, string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM students WHERE active = 1 AND ' . $condition . ' ORDER BY id LIMIT 2'
        );
        $statement->execute(['value' => $value]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1) {
            return null;
        }

        return (int) $rows[0];
    }

    /** @param array<string, mixed> $claims */
    private function nullableClaim(array $claims, string $key, int $maxLength): ?string
    {
        $value = $claims[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function revokeSessions(int $identityId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE oidc_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE identity_id = :identity_id AND revoked_at IS NULL'
        );
        $statement->execute(['identity_id' => $identityId]);
    }

    private function assertChanged(int $rowCount): void
    {
        if ($rowCount < 1) {
            throw new RuntimeException('Die OIDC-Identität wurde nicht gefunden oder nicht geändert.');
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
