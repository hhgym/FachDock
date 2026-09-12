<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609120001';
    }

    public function description(): string
    {
        return 'Add local staff user lifecycle metadata';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE staff_users '
            . 'ADD COLUMN deactivated_at DATETIME NULL AFTER active, '
            . 'ADD COLUMN anonymized_at DATETIME NULL AFTER deactivated_at, '
            . 'ADD INDEX idx_staff_users_lifecycle (active, anonymized_at)'
        );
    }
};
