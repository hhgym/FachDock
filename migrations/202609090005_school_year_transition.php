<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090005';
    }

    public function description(): string
    {
        return 'Add school year rollover runs, renewal reminder tracking and templates';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE school_year_transition_runs ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'source_school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'target_school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'status VARCHAR(24) NOT NULL,'
            . 'summary_json LONGTEXT NOT NULL,'
            . 'initiated_by_type VARCHAR(24) NOT NULL,'
            . 'initiated_by_id BIGINT UNSIGNED NULL,'
            . 'started_at DATETIME NOT NULL,'
            . 'finished_at DATETIME NULL,'
            . 'INDEX idx_school_year_transition_source (source_school_year_id, started_at),'
            . 'INDEX idx_school_year_transition_target (target_school_year_id, started_at),'
            . 'CONSTRAINT fk_school_year_transition_source FOREIGN KEY (source_school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_school_year_transition_target FOREIGN KEY (target_school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_school_year_transition_staff FOREIGN KEY (initiated_by_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE school_year_reminder_dispatches ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'source_school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'target_school_year_id BIGINT UNSIGNED NOT NULL,'
            . 'student_id BIGINT UNSIGNED NOT NULL,'
            . 'reminder_key VARCHAR(32) NOT NULL,'
            . 'mail_queue_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_school_year_reminder (source_school_year_id, target_school_year_id, student_id, reminder_key),'
            . 'INDEX idx_school_year_reminder_created (created_at),'
            . 'CONSTRAINT fk_school_year_reminder_source FOREIGN KEY (source_school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_school_year_reminder_target FOREIGN KEY (target_school_year_id) REFERENCES school_years(id),'
            . 'CONSTRAINT fk_school_year_reminder_student FOREIGN KEY (student_id) REFERENCES students(id),'
            . 'CONSTRAINT fk_school_year_reminder_mail FOREIGN KEY (mail_queue_id) REFERENCES mail_queue(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $insert = $pdo->prepare(
            'INSERT INTO mail_templates '
            . '(template_key, version, subject_template, html_template, text_template, allowed_placeholders, active, created_at) '
            . 'VALUES (:template_key, 1, :subject_template, :html_template, :text_template, :allowed_placeholders, 1, CURRENT_TIMESTAMP)'
        );

        $insert->execute([
            'template_key' => 'school_year_renewal_reminder',
            'subject_template' => 'Schließfach für {{student_name}} im Schuljahr {{target_school_year}}',
            'html_template' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die Schließfachbuchung für <strong>{{student_name}}</strong> endet am {{source_end_date}}.</p><p>{{renewal_message}}</p><p><a href="{{booking_url}}">Schließfach für {{target_school_year}} auswählen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
            'text_template' => "Guten Tag{{parent_name_suffix}},\n\ndie Schließfachbuchung für {{student_name}} endet am {{source_end_date}}.\n{{renewal_message}}\n\nSchließfach für {{target_school_year}} auswählen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
            'allowed_placeholders' => '["student_name","parent_name_suffix","source_end_date","renewal_message","target_school_year","booking_url","school_name"]',
        ]);

        $insert->execute([
            'template_key' => 'school_year_rollover_notice',
            'subject_template' => 'Schließfachbuchung für {{student_name}} beendet',
            'html_template' => '<p>Guten Tag{{parent_name_suffix}},</p><p>das Schuljahr {{source_school_year}} ist beendet. Die bisherige Buchung für <strong>{{student_name}}</strong> wurde planmäßig geschlossen.</p><p>{{next_step}}</p><p><a href="{{booking_url}}">Buchungen anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
            'text_template' => "Guten Tag{{parent_name_suffix}},\n\ndas Schuljahr {{source_school_year}} ist beendet. Die bisherige Buchung für {{student_name}} wurde planmäßig geschlossen.\n{{next_step}}\n\nBuchungen anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
            'allowed_placeholders' => '["student_name","parent_name_suffix","source_school_year","next_step","booking_url","school_name"]',
        ]);
    }
};
