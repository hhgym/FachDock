<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080005';
    }

    public function description(): string
    {
        return 'Add school years, allocation rules, bookings, assignments and reservation slots';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE school_years ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'label VARCHAR(16) NOT NULL UNIQUE,'
            . 'starts_on DATE NOT NULL UNIQUE,'
            . 'ends_on DATE NOT NULL UNIQUE,'
            . "status VARCHAR(16) NOT NULL DEFAULT 'future',"
            . 'new_booking_opens_on DATE NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_school_year_dates (starts_on, ends_on),'
            . 'INDEX idx_school_year_status (status)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE allocation_rules ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'name VARCHAR(255) NOT NULL,'
            . 'rule_kind VARCHAR(24) NOT NULL,'
            . 'min_grade TINYINT UNSIGNED NOT NULL,'
            . 'max_grade TINYINT UNSIGNED NOT NULL,'
            . 'building_id BIGINT UNSIGNED NULL,'
            . 'floor_id BIGINT UNSIGNED NULL,'
            . 'area_id BIGINT UNSIGNED NULL,'
            . 'cabinet_group_id BIGINT UNSIGNED NULL,'
            . 'weight INT NOT NULL DEFAULT 0,'
            . 'priority INT NOT NULL DEFAULT 100,'
            . 'valid_from_school_year_id BIGINT UNSIGNED NULL,'
            . 'valid_until_school_year_id BIGINT UNSIGNED NULL,'
            . 'notes TEXT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_allocation_rules_grade (active, min_grade, max_grade, priority),'
            . 'INDEX idx_allocation_rules_validity (valid_from_school_year_id, valid_until_school_year_id),'
            . 'CONSTRAINT fk_allocation_rule_building FOREIGN KEY (building_id) REFERENCES buildings(id),'
            . 'CONSTRAINT fk_allocation_rule_floor FOREIGN KEY (floor_id) REFERENCES floors(id),'
            . 'CONSTRAINT fk_allocation_rule_area FOREIGN KEY (area_id) REFERENCES areas(id),'
            . 'CONSTRAINT fk_allocation_rule_group FOREIGN KEY (cabinet_group_id) REFERENCES cabinet_groups(id),'
            . 'CONSTRAINT fk_allocation_rule_valid_from FOREIGN KEY (valid_from_school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_allocation_rule_valid_until FOREIGN KEY (valid_until_school_year_id) REFERENCES school_years(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE school_year_rule_overrides ('
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'allocation_rule_id BIGINT UNSIGNED NOT NULL,'
            . 'enabled TINYINT(1) NOT NULL DEFAULT 1,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (school_year_id, allocation_rule_id),'
            . 'CONSTRAINT fk_rule_override_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_rule_override_rule FOREIGN KEY (allocation_rule_id) REFERENCES allocation_rules(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE bookings ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'status VARCHAR(32) NOT NULL,'
            . 'projected_grade TINYINT UNSIGNED NOT NULL,'
            . 'valid_from DATE NOT NULL,'
            . 'valid_until DATE NOT NULL,'
            . 'initiated_by_type VARCHAR(32) NOT NULL,'
            . 'initiated_by_id BIGINT UNSIGNED NULL,'
            . 'annual_fee_cents INT UNSIGNED NULL,'
            . 'charged_fee_cents INT UNSIGNED NULL,'
            . 'proration_months TINYINT UNSIGNED NULL,'
            . 'fee_exemption_type VARCHAR(32) NULL,'
            . 'previous_booking_id BIGINT UNSIGNED NULL,'
            . 'student_snapshot LONGTEXT NOT NULL,'
            . 'rule_snapshot LONGTEXT NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'ended_at DATETIME NULL,'
            . 'INDEX idx_bookings_student_year (student_id, school_year_id, created_at),'
            . 'INDEX idx_bookings_year_status (school_year_id, status),'
            . 'CONSTRAINT fk_booking_student FOREIGN KEY (student_id) REFERENCES students(id),'
            . 'CONSTRAINT fk_booking_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_booking_previous FOREIGN KEY (previous_booking_id) REFERENCES bookings(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE booking_slots ('
            . 'booking_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_booking_slot_student_year (school_year_id, student_id),'
            . 'CONSTRAINT fk_booking_slot_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_booking_slot_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_booking_slot_student FOREIGN KEY (student_id) REFERENCES students(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE locker_assignment_history ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'booking_id BIGINT UNSIGNED NOT NULL,'
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'locker_id BIGINT UNSIGNED NOT NULL,'
            . 'starts_at DATETIME NOT NULL,'
            . 'ends_at DATETIME NULL,'
            . 'reason VARCHAR(64) NOT NULL,'
            . 'actor_type VARCHAR(32) NOT NULL,'
            . 'actor_id BIGINT UNSIGNED NULL,'
            . 'locker_snapshot LONGTEXT NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_assignment_booking (booking_id, starts_at),'
            . 'INDEX idx_assignment_locker_year (locker_id, school_year_id, starts_at),'
            . 'CONSTRAINT fk_assignment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_assignment_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_assignment_locker FOREIGN KEY (locker_id) REFERENCES lockers(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE locker_occupancies ('
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'locker_id BIGINT UNSIGNED NOT NULL,'
            . 'booking_id BIGINT UNSIGNED NOT NULL UNIQUE,'
            . 'assigned_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (school_year_id, locker_id),'
            . 'CONSTRAINT fk_occupancy_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_occupancy_locker FOREIGN KEY (locker_id) REFERENCES lockers(id),'
            . 'CONSTRAINT fk_occupancy_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE locker_reservations ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'locker_id BIGINT UNSIGNED NOT NULL,'
            . 'status VARCHAR(32) NOT NULL,'
            . 'expires_at DATETIME NOT NULL,'
            . 'payment_grace_expires_at DATETIME NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_reservation_expiry (status, expires_at),'
            . 'INDEX idx_reservation_student_year (student_id, school_year_id, created_at),'
            . 'INDEX idx_reservation_locker_year (locker_id, school_year_id, created_at),'
            . 'CONSTRAINT fk_reservation_student FOREIGN KEY (student_id) REFERENCES students(id),'
            . 'CONSTRAINT fk_reservation_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_reservation_locker FOREIGN KEY (locker_id) REFERENCES lockers(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE reservation_slots ('
            . 'reservation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
            . 'school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . 'locker_id BIGINT UNSIGNED NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_reservation_slot_student_year (school_year_id, student_id),'
            . 'UNIQUE KEY uq_reservation_slot_locker_year (school_year_id, locker_id),'
            . 'CONSTRAINT fk_reservation_slot_reservation FOREIGN KEY (reservation_id) REFERENCES locker_reservations(id),'
            . 'CONSTRAINT fk_reservation_slot_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_reservation_slot_student FOREIGN KEY (student_id) REFERENCES students(id),'
            . 'CONSTRAINT fk_reservation_slot_locker FOREIGN KEY (locker_id) REFERENCES lockers(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
