<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609100001';
    }

    public function description(): string
    {
        return 'Allow automated privacy runs without a staff actor';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE privacy_anonymization_runs MODIFY staff_user_id BIGINT UNSIGNED NULL');
    }
};
