<?php

declare(strict_types=1);

namespace FachDock\Parent;

use DomainException;
use FachDock\Mail\MailQueueService;
use FachDock\Mail\MailWorker;
use PDO;
use RuntimeException;

final class ParentPortalAccessService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ParentMagicLinkService $magicLinks,
        private readonly MailQueueService $mailQueue,
        private readonly string $baseUrl,
        private readonly string $schoolName,
        private readonly int $magicLinkMinutes = 15,
        private readonly int $maxRequestsPerWindow = 5,
        private readonly int $requestWindowMinutes = 15,
        private readonly ?MailWorker $immediateMailWorker = null,
    ) {
    }

    public function requestLogin(string $email, string $ipAddress, string $userAgent): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $parent = $this->verifiedParentByEmail($email);
        if ($parent === null || $this->isRateLimited($parent['id'])) {
            return;
        }

        $this->queueMagicLink(
            $parent,
            ParentMagicLinkPurpose::Login,
            'parent_login',
            '/parent/magic/login?token=',
            $ipAddress,
            $userAgent,
        );
    }

    public function sendVerification(int $parentContactId, string $ipAddress, string $userAgent): int
    {
        $parent = $this->parentById($parentContactId);
        if (!$parent['active']) {
            throw new DomainException('Der Elternkontakt ist deaktiviert.');
        }
        if ($parent['verified']) {
            throw new DomainException('Die E-Mail-Adresse ist bereits bestätigt.');
        }
        if ($this->isRateLimited($parentContactId, ParentMagicLinkPurpose::VerifyEmail)) {
            throw new DomainException('Für diesen Elternkontakt wurden kürzlich bereits mehrere Bestätigungslinks erzeugt.');
        }

        return $this->queueMagicLink(
            $parent,
            ParentMagicLinkPurpose::VerifyEmail,
            'parent_verify_email',
            '/parent/magic/verify?token=',
            $ipAddress,
            $userAgent,
        );
    }

    /** @return list<array{id: int, first_name: string, last_name: string, class_name: string, grade: int}> */
    public function children(int $parentContactId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.first_name, s.last_name, s.class_name, s.grade '
            . 'FROM parent_student_link_slots slot '
            . 'INNER JOIN students s ON s.id = slot.student_id '
            . 'WHERE slot.parent_contact_id = :parent_contact_id AND s.active = 1 '
            . 'ORDER BY s.grade, s.class_name, s.last_name, s.first_name'
        );
        $statement->execute(['parent_contact_id' => $parentContactId]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'class_name' => (string) $row['class_name'],
                'grade' => (int) $row['grade'],
            ];
        }

        return $result;
    }

    /**
     * @param array{id: int, email: string, first_name: string|null, last_name: string|null, active: bool, verified: bool} $parent
     */
    private function queueMagicLink(
        array $parent,
        ParentMagicLinkPurpose $purpose,
        string $templateKey,
        string $path,
        string $ipAddress,
        string $userAgent,
    ): int {
        $baseUrl = rtrim(trim($this->baseUrl), '/');
        if (!preg_match('#^https://[^/]+(?:/.*)?$#i', $baseUrl)) {
            throw new DomainException('Für Magic Links muss eine kanonische HTTPS-Basis-URL konfiguriert sein.');
        }

        $issued = $this->magicLinks->issue($parent['id'], $purpose, $ipAddress, $userAgent);
        $name = trim(($parent['first_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
        $suffix = $name === '' ? '' : ' ' . $name;
        $reference = $purpose->value . ':' . $parent['id'] . ':' . bin2hex(random_bytes(12));

        $queueId = $this->mailQueue->enqueue(
            $templateKey,
            $parent['email'],
            $name === '' ? null : $name,
            [
                'school_name' => $this->schoolName !== '' ? $this->schoolName : 'FachDock',
                'parent_name_suffix' => $suffix,
                'expires_minutes' => max(1, min(1440, $this->magicLinkMinutes)),
            ],
            ['magic_link' => $baseUrl . $path . rawurlencode($issued->token)],
            'parent_contact',
            $parent['id'],
            $reference,
            $reference,
            MailWorker::IMMEDIATE_PRIORITY_MAX,
            $issued->expiresAt,
        );

        $this->immediateMailWorker?->runImmediate($queueId);

        return $queueId;
    }

    /** @return array{id: int, email: string, first_name: string|null, last_name: string|null, active: bool, verified: bool}|null */
    private function verifiedParentByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, active, verified_at FROM parent_contacts '
            . 'WHERE email = :email AND active = 1 AND verified_at IS NOT NULL LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeParent($row);
    }

    /** @return array{id: int, email: string, first_name: string|null, last_name: string|null, active: bool, verified: bool} */
    private function parentById(int $parentContactId): array
    {
        if ($parentContactId < 1) {
            throw new DomainException('Der Elternkontakt ist ungültig.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, active, verified_at FROM parent_contacts WHERE id = :id'
        );
        $statement->execute(['id' => $parentContactId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Der Elternkontakt existiert nicht.');
        }

        return $this->normalizeParent($row);
    }

    private function isRateLimited(
        int $parentContactId,
        ParentMagicLinkPurpose $purpose = ParentMagicLinkPurpose::Login,
    ): bool {
        $window = max(1, min(1440, $this->requestWindowMinutes));
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM parent_magic_links WHERE parent_contact_id = :parent_contact_id '
            . 'AND purpose = :purpose AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $window . ' MINUTE)'
        );
        $statement->execute([
            'parent_contact_id' => $parentContactId,
            'purpose' => $purpose->value,
        ]);
        $count = $statement->fetchColumn();

        return (int) $count >= max(1, $this->maxRequestsPerWindow);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, email: string, first_name: string|null, last_name: string|null, active: bool, verified: bool}
     */
    private function normalizeParent(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $email = (string) ($row['email'] ?? '');
        if ($id < 1 || $email === '') {
            throw new RuntimeException('Der Elternkontakt ist inkonsistent.');
        }

        return [
            'id' => $id,
            'email' => $email,
            'first_name' => $row['first_name'] === null ? null : (string) $row['first_name'],
            'last_name' => $row['last_name'] === null ? null : (string) $row['last_name'],
            'active' => (int) ($row['active'] ?? 0) === 1,
            'verified' => $row['verified_at'] !== null,
        ];
    }
}
