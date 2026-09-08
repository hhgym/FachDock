<?php

declare(strict_types=1);

namespace FachDock\Audit;

use FachDock\Auth\AuthenticatedStaff;
use FachDock\Parent\AuthenticatedParent;
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
        $this->write('staff', $staff->id, null, $action, $entityType, $entityId, $metadata);
    }

    /** @param array<string, mixed> $metadata
     *  @throws JsonException
     */
    public function parent(
        AuthenticatedParent $parent,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        array $metadata = [],
    ): void {
        $this->write('parent', null, $parent->id, $action, $entityType, $entityId, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws JsonException
     */
    private function write(
        string $actorType,
        ?int $staffUserId,
        ?int $parentContactId,
        string $action,
        string $entityType,
        int|string|null $entityId,
        array $metadata,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log '
            . '(actor_type, staff_user_id, parent_contact_id, action, entity_type, entity_id, metadata, created_at) '
            . 'VALUES (:actor_type, :staff_user_id, :parent_contact_id, :action, :entity_type, :entity_id, '
            . ':metadata, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'actor_type' => $actorType,
            'staff_user_id' => $staffUserId,
            'parent_contact_id' => $parentContactId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
