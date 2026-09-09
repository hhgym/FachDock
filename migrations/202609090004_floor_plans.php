<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090004';
    }

    public function description(): string
    {
        return 'Add floor plans and cabinet group placements';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE floor_plans ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'floor_id BIGINT UNSIGNED NOT NULL,'
            . 'title VARCHAR(255) NOT NULL,'
            . 'storage_path VARCHAR(255) NOT NULL UNIQUE,'
            . 'original_name VARCHAR(255) NOT NULL,'
            . 'mime_type VARCHAR(64) NOT NULL,'
            . 'sort_order INT NOT NULL DEFAULT 0,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_by_staff_user_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_floor_plans_floor (floor_id, active, sort_order, id),'
            . 'CONSTRAINT fk_floor_plans_floor FOREIGN KEY (floor_id) REFERENCES floors(id),'
            . 'CONSTRAINT fk_floor_plans_staff FOREIGN KEY (created_by_staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE floor_plan_group_positions ('
            . 'floor_plan_id BIGINT UNSIGNED NOT NULL,'
            . 'cabinet_group_id BIGINT UNSIGNED NOT NULL,'
            . 'x_percent DECIMAL(6,3) NOT NULL,'
            . 'y_percent DECIMAL(6,3) NOT NULL,'
            . 'width_percent DECIMAL(6,3) NOT NULL DEFAULT 8.000,'
            . 'height_percent DECIMAL(6,3) NOT NULL DEFAULT 8.000,'
            . 'updated_by_staff_user_id BIGINT UNSIGNED NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (floor_plan_id, cabinet_group_id),'
            . 'INDEX idx_floor_plan_positions_group (cabinet_group_id),'
            . 'CONSTRAINT fk_floor_plan_position_plan FOREIGN KEY (floor_plan_id) REFERENCES floor_plans(id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_floor_plan_position_group FOREIGN KEY (cabinet_group_id) REFERENCES cabinet_groups(id),'
            . 'CONSTRAINT fk_floor_plan_position_staff FOREIGN KEY (updated_by_staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
