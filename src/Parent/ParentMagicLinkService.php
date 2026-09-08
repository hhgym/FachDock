<?php

declare(strict_types=1);

namespace FachDock\Parent;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class ParentMagicLinkService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetimeMinutes = 15,
        private readonly ParentMagicLinkToken $tokens = new ParentMagicLinkToken(),
    ) {
    }

    public function issue(
        int $parentContactId,
        ParentMagicLinkPurpose $purpose,
        ?string $requestedIp = null,
        ?string $userAgent = null,
    ): string {
        if ($parentContactId < 1) {
            throw new DomainException('Der Elternkontakt ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $parent = $this->loadParentForUpdate($parentContactId);
            if (!$parent['active']) {
                throw new DomainException('Der Elternkontakt ist deaktiviert.');
            }
            if ($purpose === ParentMagicLinkPurpose::Login && !$parent['verified']) {
                throw new DomainException('Vor der Anmeldung muss die E-Mail-Adresse bestätigt werden.');
            }

            $this->pdo->prepare(
                'UPDATE parent_magic_links SET consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP) '
                . 'WHERE parent_contact_id = :parent_contact_id AND purpose = :purpose '
                . 'AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
            )->execute([
                'parent_contact_id' => $parentContactId,
                'purpose' => $purpose->value,
            ]);

            $rawToken = $this->tokens->generate();
            $lifetime = max(1, min(1440, $this->lifetimeMinutes));
            $statement = $this->pdo->prepare(
                'INSERT INTO parent_magic_links '
                . '(parent_contact_id, token_hash, purpose, expires_at, requested_ip, user_agent, created_at) '
                . 'VALUES (:parent_contact_id, :token_hash, :purpose, '
                . 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $lifetime . ' MINUTE), '
                . ':requested_ip, :user_agent, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'parent_contact_id' => $parentContactId,
                'token_hash' => $this->tokens->hash($rawToken),
                'purpose' => $purpose->value,
                'requested_ip' => $this->nullableLimited($requestedIp, 45),
                'user_agent' => $this->nullableLimited($userAgent, 500),
            ]);
            $this->pdo->commit();

            return $rawToken;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function consume(string $rawToken, ParentMagicLinkPurpose $purpose): int
    {
        $tokenHash = $this->tokens->hash($rawToken);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT pml.id, pml.parent_contact_id, pc.active, pc.verified_at '
                . 'FROM parent_magic_links pml '
                . 'INNER JOIN parent_contacts pc ON pc.id = pml.parent_contact_id '
                . 'WHERE pml.token_hash = :token_hash AND pml.purpose = :purpose '
                . 'AND pml.consumed_at IS NULL AND pml.expires_at > CURRENT_TIMESTAMP FOR UPDATE'
            );
            $statement->execute([
                'token_hash' => $tokenHash,
                'purpose' => $purpose->value,
            ]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new DomainException('Der Magic Link ist ungültig, abgelaufen oder bereits verwendet.');
            }
            if ((int) $row['active'] !== 1) {
                throw new DomainException('Der Elternkontakt ist deaktiviert.');
            }
            if ($purpose === ParentMagicLinkPurpose::Login && $row['verified_at'] === null) {
                throw new DomainException('Die E-Mail-Adresse ist noch nicht bestätigt.');
            }

            $linkId = (int) $row['id'];
            $parentContactId = (int) $row['parent_contact_id'];
            if ($parentContactId < 1) {
                throw new RuntimeException('Der Magic Link verweist auf keinen gültigen Elternkontakt.');
            }

            $consume = $this->pdo->prepare(
                'UPDATE parent_magic_links SET consumed_at = CURRENT_TIMESTAMP '
                . 'WHERE id = :id AND consumed_at IS NULL'
            );
            $consume->execute(['id' => $linkId]);
            if ($consume->rowCount() !== 1) {
                throw new DomainException('Der Magic Link wurde bereits verwendet.');
            }

            if ($purpose === ParentMagicLinkPurpose::VerifyEmail) {
                $this->pdo->prepare(
                    "UPDATE parent_contacts SET status = 'verified', verified_at = COALESCE(verified_at, CURRENT_TIMESTAMP), "
                    . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute(['id' => $parentContactId]);
            }

            $this->pdo->commit();

            return $parentContactId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{active: bool, verified: bool} */
    private function loadParentForUpdate(int $parentContactId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT active, verified_at FROM parent_contacts WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $parentContactId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Der Elternkontakt existiert nicht.');
        }

        return [
            'active' => (int) $row['active'] === 1,
            'verified' => $row['verified_at'] !== null,
        ];
    }

    private function nullableLimited(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
