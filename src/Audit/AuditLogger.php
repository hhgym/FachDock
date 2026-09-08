<?php

declare(strict_types=1);

namespace FachDock\Audit;

use FachDock\Auth\AuthenticatedStaff;
use JsonException;
use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $metadata
     *  @throws JsonException
     */
    public function staff(
        AuthenticatedStaff $staff,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        array $metadata = [],
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log '
            . '(actor_type, staff_user_id, action, entity_type, entity_id, metadata, created_at) '
            . 'VALUES (:actor_type, :staff_user_id, :action, :entity_type, :entity_id, :metadata, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'actor_type' => 'staff',
            'staff_user_id' => $staff->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
