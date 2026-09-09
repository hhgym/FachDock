<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090002';
    }

    public function description(): string
    {
        return 'Add booking lifecycle events and lifecycle mail templates';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE booking_lifecycle_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'booking_id BIGINT UNSIGNED NOT NULL,'
            . 'event_type VARCHAR(32) NOT NULL,'
            . 'related_booking_id BIGINT UNSIGNED NULL,'
            . 'old_locker_id BIGINT UNSIGNED NULL,'
            . 'new_locker_id BIGINT UNSIGNED NULL,'
            . 'effective_on DATE NULL,'
            . 'reason VARCHAR(1000) NOT NULL,'
            . 'actor_type VARCHAR(32) NOT NULL,'
            . 'actor_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'INDEX idx_booking_lifecycle_booking (booking_id, created_at, id),'
            . 'INDEX idx_booking_lifecycle_related (related_booking_id),'
            . 'CONSTRAINT fk_booking_lifecycle_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_booking_lifecycle_related FOREIGN KEY (related_booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_booking_lifecycle_old_locker FOREIGN KEY (old_locker_id) REFERENCES lockers(id),'
            . 'CONSTRAINT fk_booking_lifecycle_new_locker FOREIGN KEY (new_locker_id) REFERENCES lockers(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $insert = $pdo->prepare(
            'INSERT INTO mail_templates '
            . '(template_key, version, subject_template, html_template, text_template, allowed_placeholders, active, created_at) '
            . 'VALUES (:template_key, 1, :subject_template, :html_template, :text_template, :allowed_placeholders, 1, CURRENT_TIMESTAMP)'
        );

        $templates = [
            [
                'key' => 'booking_locker_changed',
                'subject' => 'Schließfach für {{student_name}} geändert',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>das Schließfach für <strong>{{student_name}}</strong> wurde geändert.</p><p>Bisher: {{old_locker_name}}<br>Neu: {{new_locker_name}}<br>Schuljahr: {{school_year}}</p><p>Grund: {{reason}}</p><p><a href="{{booking_url}}">Buchung anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndas Schließfach für {{student_name}} wurde geändert.\nBisher: {{old_locker_name}}\nNeu: {{new_locker_name}}\nSchuljahr: {{school_year}}\nGrund: {{reason}}\n\nBuchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'old_locker_name', 'new_locker_name', 'school_year', 'reason', 'booking_url'],
            ],
            [
                'key' => 'booking_ended',
                'subject' => 'Schließfachbuchung für {{student_name}} beendet',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die Schließfachbuchung für <strong>{{student_name}}</strong> wurde zum {{effective_on}} beendet.</p><p>Schuljahr: {{school_year}}<br>Schließfach: {{locker_name}}<br>Grund: {{reason}}</p><p><a href="{{booking_url}}">Buchung anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie Schließfachbuchung für {{student_name}} wurde zum {{effective_on}} beendet.\nSchuljahr: {{school_year}}\nSchließfach: {{locker_name}}\nGrund: {{reason}}\n\nBuchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'effective_on', 'school_year', 'locker_name', 'reason', 'booking_url'],
            ],
            [
                'key' => 'booking_cancelled',
                'subject' => 'Schließfachbuchung für {{student_name}} storniert',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die Schließfachbuchung für <strong>{{student_name}}</strong> wurde storniert.</p><p>Schuljahr: {{school_year}}<br>Schließfach: {{locker_name}}<br>Grund: {{reason}}</p><p><a href="{{booking_url}}">Buchung anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie Schließfachbuchung für {{student_name}} wurde storniert.\nSchuljahr: {{school_year}}\nSchließfach: {{locker_name}}\nGrund: {{reason}}\n\nBuchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'school_year', 'locker_name', 'reason', 'booking_url'],
            ],
            [
                'key' => 'booking_renewed',
                'subject' => 'Schließfachbuchung für {{student_name}} verlängert',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die Schließfachbuchung für <strong>{{student_name}}</strong> wurde für das Schuljahr {{school_year}} verlängert.</p><p>Schließfach: {{locker_name}}</p><p>{{next_step}}</p><p><a href="{{booking_url}}">Neue Buchung anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie Schließfachbuchung für {{student_name}} wurde für das Schuljahr {{school_year}} verlängert.\nSchließfach: {{locker_name}}\n{{next_step}}\n\nNeue Buchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'school_year', 'locker_name', 'next_step', 'booking_url'],
            ],
        ];

        foreach ($templates as $template) {
            $insert->execute([
                'template_key' => $template['key'],
                'subject_template' => $template['subject'],
                'html_template' => $template['html'],
                'text_template' => $template['text'],
                'allowed_placeholders' => json_encode($template['placeholders'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
