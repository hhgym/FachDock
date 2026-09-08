<?php

declare(strict_types=1);

use FachDock\Migration\Migration;

return new class () implements Migration {
    public function version(): string
    {
        return '202609080008';
    }

    public function description(): string
    {
        return 'Add versioned mail templates and persistent delivery queue';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE mail_templates ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'template_key VARCHAR(64) NOT NULL,'
            . 'version INT UNSIGNED NOT NULL,'
            . 'subject_template VARCHAR(500) NOT NULL,'
            . 'html_template LONGTEXT NOT NULL,'
            . 'text_template LONGTEXT NOT NULL,'
            . 'allowed_placeholders LONGTEXT NOT NULL,'
            . 'active TINYINT(1) NOT NULL DEFAULT 0,'
            . 'created_by_staff_user_id BIGINT UNSIGNED NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_mail_template_version (template_key, version),'
            . 'INDEX idx_mail_templates_active (template_key, active, version),'
            . 'CONSTRAINT fk_mail_templates_staff FOREIGN KEY (created_by_staff_user_id) REFERENCES staff_users(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE mail_queue ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'mail_template_id BIGINT UNSIGNED NOT NULL,'
            . 'recipient_email VARCHAR(255) NOT NULL,'
            . 'recipient_name VARCHAR(255) NULL,'
            . 'subject VARCHAR(500) NOT NULL,'
            . 'html_body LONGTEXT NULL,'
            . 'text_body LONGTEXT NULL,'
            . 'placeholder_snapshot LONGTEXT NOT NULL,'
            . 'relation_type VARCHAR(64) NULL,'
            . 'relation_id BIGINT UNSIGNED NULL,'
            . 'business_reference VARCHAR(191) NULL,'
            . 'deduplication_key VARCHAR(191) NULL,'
            . 'priority SMALLINT NOT NULL DEFAULT 100,'
            . "status VARCHAR(32) NOT NULL DEFAULT 'waiting',"
            . 'attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'available_at DATETIME NOT NULL,'
            . 'not_after DATETIME NULL,'
            . 'locked_at DATETIME NULL,'
            . 'sent_at DATETIME NULL,'
            . 'failed_at DATETIME NULL,'
            . 'canceled_at DATETIME NULL,'
            . 'last_error VARCHAR(1000) NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'UNIQUE KEY uq_mail_queue_deduplication (deduplication_key),'
            . 'INDEX idx_mail_queue_worker (status, available_at, priority, id),'
            . 'INDEX idx_mail_queue_sent (sent_at),'
            . 'INDEX idx_mail_queue_relation (relation_type, relation_id),'
            . 'CONSTRAINT fk_mail_queue_template FOREIGN KEY (mail_template_id) REFERENCES mail_templates(id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $insert = $pdo->prepare(
            'INSERT INTO mail_templates '
            . '(template_key, version, subject_template, html_template, text_template, allowed_placeholders, active, created_at) '
            . 'VALUES (:template_key, 1, :subject_template, :html_template, :text_template, :allowed_placeholders, 1, CURRENT_TIMESTAMP)'
        );

        $insert->execute([
            'template_key' => 'parent_verify_email',
            'subject_template' => 'E-Mail-Adresse für {{school_name}} bestätigen',
            'html_template' => '<p>Guten Tag{{parent_name_suffix}},</p><p>bitte bestätigen Sie Ihre E-Mail-Adresse für {{school_name}}.</p><p><a href="{{magic_link}}">E-Mail-Adresse bestätigen</a></p><p>Der Link ist {{expires_minutes}} Minuten gültig und kann nur einmal verwendet werden.</p>',
            'text_template' => "Guten Tag{{parent_name_suffix}},\n\nbitte bestätigen Sie Ihre E-Mail-Adresse für {{school_name}}:\n{{magic_link}}\n\nDer Link ist {{expires_minutes}} Minuten gültig und kann nur einmal verwendet werden.",
            'allowed_placeholders' => '["school_name","parent_name_suffix","magic_link","expires_minutes"]',
        ]);
        $insert->execute([
            'template_key' => 'parent_login',
            'subject_template' => 'Anmeldung bei {{school_name}}',
            'html_template' => '<p>Guten Tag{{parent_name_suffix}},</p><p>über den folgenden Link können Sie sich bei FachDock für {{school_name}} anmelden.</p><p><a href="{{magic_link}}">Bei FachDock anmelden</a></p><p>Der Link ist {{expires_minutes}} Minuten gültig und kann nur einmal verwendet werden.</p>',
            'text_template' => "Guten Tag{{parent_name_suffix}},\n\nüber den folgenden Link können Sie sich bei FachDock für {{school_name}} anmelden:\n{{magic_link}}\n\nDer Link ist {{expires_minutes}} Minuten gültig und kann nur einmal verwendet werden.",
            'allowed_placeholders' => '["school_name","parent_name_suffix","magic_link","expires_minutes"]',
        ]);
    }
};
