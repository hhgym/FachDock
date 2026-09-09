<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609090001';
    }

    public function description(): string
    {
        return 'Add payment notification templates and Stripe payments for existing bookings';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE payments '
            . 'DROP FOREIGN KEY fk_payment_reservation, '
            . 'MODIFY reservation_id BIGINT UNSIGNED NULL, '
            . 'ADD CONSTRAINT fk_payment_reservation FOREIGN KEY (reservation_id) REFERENCES locker_reservations(id)'
        );

        $pdo->exec(
            'CREATE TABLE booking_payment_attempt_slots ('
            . 'booking_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
            . 'payment_id BIGINT UNSIGNED NOT NULL UNIQUE,'
            . 'created_at DATETIME NOT NULL,'
            . 'CONSTRAINT fk_booking_payment_slot_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),'
            . 'CONSTRAINT fk_booking_payment_slot_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $insert = $pdo->prepare(
            'INSERT INTO mail_templates '
            . '(template_key, version, subject_template, html_template, text_template, allowed_placeholders, active, created_at) '
            . 'VALUES (:template_key, 1, :subject_template, :html_template, :text_template, :allowed_placeholders, 1, CURRENT_TIMESTAMP)'
        );

        $templates = [
            [
                'key' => 'booking_confirmed',
                'subject' => 'Schließfachbuchung für {{student_name}} bestätigt',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die Schließfachbuchung für <strong>{{student_name}}</strong> wurde erfolgreich abgeschlossen.</p><p>Schuljahr: {{school_year}}<br>Schließfach: {{locker_name}}</p><p>Den aktuellen Buchungsstatus finden Sie im Elternportal: <a href="{{booking_url}}">Buchung anzeigen</a>.</p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie Schließfachbuchung für {{student_name}} wurde erfolgreich abgeschlossen.\nSchuljahr: {{school_year}}\nSchließfach: {{locker_name}}\n\nBuchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'school_year', 'locker_name', 'booking_url'],
            ],
            [
                'key' => 'payment_received',
                'subject' => 'Zahlung für die Schließfachbuchung eingegangen',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>wir haben Ihre Zahlung über <strong>{{amount}}</strong> für die Schließfachbuchung von {{student_name}} erhalten.</p><p>Zahlungsreferenz: {{payment_reference}}<br>Schuljahr: {{school_year}}<br>Schließfach: {{locker_name}}</p><p><a href="{{booking_url}}">Buchung im Elternportal anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\nwir haben Ihre Zahlung über {{amount}} für die Schließfachbuchung von {{student_name}} erhalten.\nZahlungsreferenz: {{payment_reference}}\nSchuljahr: {{school_year}}\nSchließfach: {{locker_name}}\n\nBuchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'amount', 'payment_reference', 'school_year', 'locker_name', 'booking_url'],
            ],
            [
                'key' => 'payment_failed',
                'subject' => 'Zahlung für die Schließfachbuchung fehlgeschlagen',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die Zahlung über <strong>{{amount}}</strong> für die Schließfachbuchung von {{student_name}} konnte nicht abgeschlossen werden.</p><p>Es wurde keine erfolgreiche Zahlung verbucht. Sie können den Zahlungsvorgang im Elternportal erneut starten.</p><p><a href="{{retry_url}}">Zahlung erneut aufrufen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie Zahlung über {{amount}} für die Schließfachbuchung von {{student_name}} konnte nicht abgeschlossen werden. Es wurde keine erfolgreiche Zahlung verbucht.\n\nZahlung erneut aufrufen: {{retry_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'amount', 'retry_url'],
            ],
            [
                'key' => 'payment_checkout_expired',
                'subject' => 'Zahlungsvorgang für die Schließfachbuchung abgelaufen',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>der begonnene Zahlungsvorgang über <strong>{{amount}}</strong> für {{student_name}} ist abgelaufen und wurde nicht belastet.</p><p>Sie können die Zahlung im Elternportal erneut starten.</p><p><a href="{{retry_url}}">Zahlung erneut aufrufen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\nder begonnene Zahlungsvorgang über {{amount}} für {{student_name}} ist abgelaufen und wurde nicht belastet.\n\nZahlung erneut aufrufen: {{retry_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'amount', 'retry_url'],
            ],
            [
                'key' => 'payment_received_booking_pending',
                'subject' => 'Zahlung eingegangen – Buchung wird geprüft',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>Ihre Zahlung über <strong>{{amount}}</strong> für {{student_name}} ist bei Stripe erfolgreich eingegangen.</p><p>Die automatische Aktivierung der Buchung konnte technisch nicht vollständig abgeschlossen werden. Die Schließfachverwaltung prüft den Vorgang. Bitte führen Sie <strong>keine weitere Zahlung</strong> durch.</p><p>Zahlungsreferenz: {{payment_reference}}</p><p><a href="{{payment_url}}">Zahlungsstatus anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\nIhre Zahlung über {{amount}} für {{student_name}} ist erfolgreich eingegangen. Die automatische Aktivierung der Buchung konnte technisch nicht vollständig abgeschlossen werden. Die Schließfachverwaltung prüft den Vorgang. Bitte führen Sie keine weitere Zahlung durch.\n\nZahlungsreferenz: {{payment_reference}}\nZahlungsstatus: {{payment_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'amount', 'payment_reference', 'payment_url'],
            ],
            [
                'key' => 'but_request_received',
                'subject' => 'BuT-Prüfung für die Schließfachbuchung eingegangen',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>für die Schließfachbuchung von <strong>{{student_name}}</strong> wurde eine Gebührenbefreiung nach Bildung und Teilhabe (BuT) angegeben.</p><p>Das Schließfach {{locker_name}} für das Schuljahr {{school_year}} ist bereits verbindlich zugeordnet. Bis zur Entscheidung ist keine Zahlung erforderlich.</p><p><a href="{{booking_url}}">Buchungsstatus anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\nfür die Schließfachbuchung von {{student_name}} wurde eine BuT-Gebührenbefreiung angegeben. Das Schließfach {{locker_name}} für das Schuljahr {{school_year}} ist bereits verbindlich zugeordnet. Bis zur Entscheidung ist keine Zahlung erforderlich.\n\nBuchungsstatus: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'school_year', 'locker_name', 'booking_url'],
            ],
            [
                'key' => 'but_approved',
                'subject' => 'BuT-Befreiung für die Schließfachbuchung bestätigt',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die BuT-Gebührenbefreiung für die Schließfachbuchung von <strong>{{student_name}}</strong> wurde bestätigt.</p><p>Für das Schließfach {{locker_name}} im Schuljahr {{school_year}} ist kein Entgelt zu zahlen. Die Buchung ist aktiv.</p><p><a href="{{booking_url}}">Buchung anzeigen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie BuT-Gebührenbefreiung für die Schließfachbuchung von {{student_name}} wurde bestätigt. Für das Schließfach {{locker_name}} im Schuljahr {{school_year}} ist kein Entgelt zu zahlen. Die Buchung ist aktiv.\n\nBuchung anzeigen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'school_year', 'locker_name', 'booking_url'],
            ],
            [
                'key' => 'but_rejected_payment_due',
                'subject' => 'Zahlung für die Schließfachbuchung erforderlich',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>die beantragte BuT-Gebührenbefreiung für die Schließfachbuchung von <strong>{{student_name}}</strong> konnte nicht bestätigt werden.</p><p>Für die Buchung sind <strong>{{amount}}</strong> zu zahlen. Zahlungsfrist: {{payment_due_at}}.</p><p><a href="{{booking_url}}">Jetzt im Elternportal bezahlen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\ndie beantragte BuT-Gebührenbefreiung für die Schließfachbuchung von {{student_name}} konnte nicht bestätigt werden.\nZu zahlen: {{amount}}\nZahlungsfrist: {{payment_due_at}}\n\nJetzt bezahlen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'amount', 'payment_due_at', 'booking_url'],
            ],
            [
                'key' => 'payment_due_reminder',
                'subject' => 'Erinnerung: Zahlung für die Schließfachbuchung offen',
                'html' => '<p>Guten Tag{{parent_name_suffix}},</p><p>für die Schließfachbuchung von <strong>{{student_name}}</strong> ist noch ein Betrag von <strong>{{amount}}</strong> offen.</p><p>Die Zahlungsfrist endet am {{payment_due_at}}.</p><p><a href="{{booking_url}}">Zahlung im Elternportal abschließen</a></p><p>Viele Grüße<br>{{school_name}}</p>',
                'text' => "Guten Tag{{parent_name_suffix}},\n\nfür die Schließfachbuchung von {{student_name}} ist noch ein Betrag von {{amount}} offen.\nZahlungsfrist: {{payment_due_at}}\n\nZahlung abschließen: {{booking_url}}\n\nViele Grüße\n{{school_name}}",
                'placeholders' => ['school_name', 'parent_name_suffix', 'student_name', 'amount', 'payment_due_at', 'booking_url'],
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
