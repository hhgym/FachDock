<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080003';
    }

    public function description(): string
    {
        return 'Create physical location, corpus and locker structure';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE buildings ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'code VARCHAR(32) NOT NULL UNIQUE,'
            . 'name VARCHAR(255) NOT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE floors ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'building_id BIGINT UNSIGNED NOT NULL,'
            . 'code VARCHAR(32) NOT NULL,'
            . 'name VARCHAR(255) NOT NULL,'
            . 'sort_order INT NOT NULL DEFAULT 0,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_floors_building_code (building_id, code),'
            . 'INDEX idx_floors_building_active (building_id, active, sort_order),'
            . 'CONSTRAINT fk_floors_building FOREIGN KEY (building_id) REFERENCES buildings(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE areas ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'floor_id BIGINT UNSIGNED NOT NULL,'
            . 'code VARCHAR(32) NOT NULL,'
            . 'name VARCHAR(255) NOT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_areas_floor_code (floor_id, code),'
            . 'INDEX idx_areas_floor_active (floor_id, active),'
            . 'CONSTRAINT fk_areas_floor FOREIGN KEY (floor_id) REFERENCES floors(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE corpus_types ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'code VARCHAR(64) NOT NULL UNIQUE,'
            . 'name VARCHAR(255) NOT NULL,'
            . 'compartment_count SMALLINT UNSIGNED NOT NULL,'
            . 'width_mm INT UNSIGNED NULL,'
            . 'height_mm INT UNSIGNED NULL,'
            . 'depth_mm INT UNSIGNED NULL,'
            . 'compartment_width_mm INT UNSIGNED NULL,'
            . 'compartment_height_mm INT UNSIGNED NULL,'
            . 'compartment_depth_mm INT UNSIGNED NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE corpus_type_positions ('
            . 'corpus_type_id BIGINT UNSIGNED NOT NULL,'
            . 'position_no SMALLINT UNSIGNED NOT NULL,'
            . 'barrier_friendly TINYINT(1) NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (corpus_type_id, position_no),'
            . 'CONSTRAINT fk_corpus_type_positions_type FOREIGN KEY (corpus_type_id) '
            . 'REFERENCES corpus_types(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE cabinet_groups ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'area_id BIGINT UNSIGNED NOT NULL,'
            . 'code VARCHAR(16) NOT NULL UNIQUE,'
            . 'name VARCHAR(255) NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'structure_locked_at DATETIME NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_cabinet_groups_area_active (area_id, active),'
            . 'CONSTRAINT fk_cabinet_groups_area FOREIGN KEY (area_id) REFERENCES areas(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE corpuses ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'cabinet_group_id BIGINT UNSIGNED NOT NULL,'
            . 'corpus_type_id BIGINT UNSIGNED NOT NULL,'
            . 'position_no SMALLINT UNSIGNED NOT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_corpuses_group_position (cabinet_group_id, position_no),'
            . 'INDEX idx_corpuses_type (corpus_type_id),'
            . 'CONSTRAINT fk_corpuses_group FOREIGN KEY (cabinet_group_id) REFERENCES cabinet_groups(id),'
            . 'CONSTRAINT fk_corpuses_type FOREIGN KEY (corpus_type_id) REFERENCES corpus_types(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE lockers ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'corpus_id BIGINT UNSIGNED NOT NULL,'
            . 'position_no SMALLINT UNSIGNED NOT NULL,'
            . 'short_name VARCHAR(64) NOT NULL UNIQUE,'
            . 'barrier_friendly TINYINT(1) NOT NULL DEFAULT 0,'
            . 'bookable TINYINT(1) NOT NULL DEFAULT 1,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . "operating_status VARCHAR(32) NOT NULL DEFAULT 'operational',"
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_lockers_corpus_position (corpus_id, position_no),'
            . 'INDEX idx_lockers_availability_base (active, bookable, operating_status),'
            . 'CONSTRAINT fk_lockers_corpus FOREIGN KEY (corpus_id) REFERENCES corpuses(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
