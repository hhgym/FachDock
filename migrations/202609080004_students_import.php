<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080004';
    }

    public function description(): string
    {
        return 'Add students, import profiles and import runs';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE students ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'matrikelnummer VARCHAR(64) NOT NULL UNIQUE,'
            . 'first_name VARCHAR(255) NOT NULL,'
            . 'last_name VARCHAR(255) NOT NULL,'
            . 'class_name VARCHAR(64) NOT NULL,'
            . 'grade TINYINT UNSIGNED NOT NULL,'
            . 'email VARCHAR(255) NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'access_code_hash CHAR(64) NULL UNIQUE,'
            . 'access_code_generated_at DATETIME NULL,'
            . 'last_import_run_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_students_class_active (class_name, active),'
            . 'INDEX idx_students_grade_active (grade, active)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE student_import_profiles ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'name VARCHAR(255) NOT NULL UNIQUE,'
            . 'delimiter_char VARCHAR(4) NOT NULL DEFAULT \';\','
            . 'enclosure_char VARCHAR(4) NOT NULL DEFAULT \'"\','
            . 'encoding VARCHAR(32) NOT NULL DEFAULT \'UTF-8\','
            . 'has_header TINYINT(1) NOT NULL DEFAULT 1,'
            . 'column_mapping LONGTEXT NOT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'last_used_at DATETIME NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE student_import_runs ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'staff_user_id BIGINT UNSIGNED NOT NULL,'
            . 'profile_id BIGINT UNSIGNED NULL,'
            . 'source_filename VARCHAR(255) NOT NULL,'
            . 'mode VARCHAR(32) NOT NULL,'
            . 'status VARCHAR(32) NOT NULL,'
            . 'summary LONGTEXT NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'completed_at DATETIME NULL,'
            . 'CONSTRAINT fk_student_import_staff FOREIGN KEY (staff_user_id) REFERENCES staff_users(id),'
            . 'CONSTRAINT fk_student_import_profile FOREIGN KEY (profile_id) REFERENCES student_import_profiles(id),'
            . 'INDEX idx_student_import_created (created_at),'
            . 'INDEX idx_student_import_status (status)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'ALTER TABLE students ADD CONSTRAINT fk_students_last_import '
            . 'FOREIGN KEY (last_import_run_id) REFERENCES student_import_runs(id)'
        );
    }
};
