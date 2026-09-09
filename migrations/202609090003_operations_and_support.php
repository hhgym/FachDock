<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090003';
    }

    public function description(): string
    {
        return 'Add worker monitoring, locker incidents, operational history and support mail templates';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE system_worker_status ('
            . 'worker_key VARCHAR(64) NOT NULL PRIMARY KEY,'
            . 'last_started_at DATETIME NULL,'
            . 'last_finished_at DATETIME NULL,'
            . 'last_success_at DATETIME NULL,'
            . 'last_failure_at DATETIME NULL,'
            . 'last_result_json LONGTEXT NULL,'
            . 'last_error VARCHAR(1000) NULL,'
            . 'updated_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE locker_incidents ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'locker_id BIGINT UNSIGNED NOT NULL,'
            . 'booking_id BIGINT UNSIGNED NULL,'
            . 'student_id BIGINT UNSIGNED NULL,'
            . 'category VARCHAR(32) NOT NULL,'
            . "status VARCHAR(24) NOT NULL DEFAULT 'open',"
            . "priority VARCHAR(16) NOT NULL DEFAULT 'normal',"
            . 'description TEXT NOT NULL,'
            . 'resolution_note TEXT NULL,'
            . 'reported_by_type VARCHAR(24) NOT NULL,'
            . 'reported_by_id BIGINT UNSIGNED NULL,'
            . 'reporter_email VARCHAR(255) NULL,'
            . 'reporter_name VARCHAR(255) NULL,'
            . 'opened_at DATETIME NOT NULL,'
            . 'resolved_at DATETIME NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'INDEX idx_locker_incidents_status (status, priority, opened_at),'
            . 'INDEX idx_locker_incidents_locker (locker_id, status, opened_at),'
            . 'INDEX idx_locker_incidents_booking (booking_id),'
            . 'INDEX idx_locker_incidents_student (student_id, opened_at),'
            . 'CONSTRAINT fk_locker_incident_locker FOREIGN KEY (locker_id) REFERENCES lockers(id),'
            . 'CONSTRAINT fk_locker_incident_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_locker_incident_student FOREIGN KEY (student_id) REFERENCES students(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE locker_incident_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'incident_id BIGINT UNSIGNED NOT NULL,'
            . 'event_type VARCHAR(48) NOT NULL,'
            . 'old_status VARCHAR(24) NULL,'
            . 'new_status VARCHAR(24) NULL,'
            . 'note TEXT NULL,'
            . 'actor_type VARCHAR(24) NOT NULL,'
            . 'actor_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_locker_incident_events_incident (incident_id, created_at),'
            . 'CONSTRAINT fk_locker_incident_event_incident FOREIGN KEY (incident_id) '
            . 'REFERENCES locker_incidents(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE locker_operation_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'locker_id BIGINT UNSIGNED NOT NULL,'
            . 'incident_id BIGINT UNSIGNED NULL,'
            . 'event_type VARCHAR(48) NOT NULL,'
            . 'old_operating_status VARCHAR(32) NULL,'
            . 'new_operating_status VARCHAR(32) NULL,'
            . 'old_bookable TINYINT(1) NULL,'
            . 'new_bookable TINYINT(1) NULL,'
            . 'note TEXT NULL,'
            . 'actor_type VARCHAR(24) NOT NULL,'
            . 'actor_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_locker_operation_events_locker (locker_id, created_at),'
            . 'INDEX idx_locker_operation_events_incident (incident_id, created_at),'
            . 'CONSTRAINT fk_locker_operation_event_locker FOREIGN KEY (locker_id) REFERENCES lockers(id),'
            . 'CONSTRAINT fk_locker_operation_event_incident FOREIGN KEY (incident_id) REFERENCES locker_incidents(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $insert = $pdo->prepare(
            'INSERT INTO mail_templates '
            . '(template_key, version, subject_template, html_template, text_template, allowed_placeholders, active, created_at) '
            . 'VALUES (:template_key, 1, :subject_template, :html_template, :text_template, :allowed_placeholders, 1, CURRENT_TIMESTAMP)'
        );

        $insert->execute([
            'template_key' => 'locker_issue_received',
            'subject_template' => 'Schließfachmeldung {{incident_reference}} eingegangen',
            'html_template' => '<p>Guten Tag{{recipient_name_suffix}},</p><p>Ihre Meldung zu Schließfach <strong>{{locker_name}}</strong> für {{student_name}} ist bei {{school_name}} eingegangen.</p><p><strong>Art:</strong> {{category_label}}</p><p>{{description}}</p><p>Vorgang: {{incident_reference}}</p><p><a href="{{status_url}}">Status ansehen</a></p>',
            'text_template' => "Guten Tag{{recipient_name_suffix}},\n\nIhre Meldung zu Schließfach {{locker_name}} für {{student_name}} ist bei {{school_name}} eingegangen.\nArt: {{category_label}}\n\n{{description}}\n\nVorgang: {{incident_reference}}\nStatus: {{status_url}}",
            'allowed_placeholders' => '["incident_reference","recipient_name_suffix","locker_name","student_name","school_name","category_label","description","status_url"]',
        ]);
        $insert->execute([
            'template_key' => 'locker_issue_resolved',
            'subject_template' => 'Schließfachmeldung {{incident_reference}} abgeschlossen',
            'html_template' => '<p>Guten Tag{{recipient_name_suffix}},</p><p>Ihre Meldung zu Schließfach <strong>{{locker_name}}</strong> für {{student_name}} wurde abgeschlossen.</p><p><strong>Ergebnis:</strong> {{resolution_note}}</p><p>Vorgang: {{incident_reference}}</p><p><a href="{{status_url}}">Vorgang ansehen</a></p>',
            'text_template' => "Guten Tag{{recipient_name_suffix}},\n\nIhre Meldung zu Schließfach {{locker_name}} für {{student_name}} wurde abgeschlossen.\nErgebnis: {{resolution_note}}\n\nVorgang: {{incident_reference}}\n{{status_url}}",
            'allowed_placeholders' => '["incident_reference","recipient_name_suffix","locker_name","student_name","resolution_note","status_url"]',
        ]);
    }
};
