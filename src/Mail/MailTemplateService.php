<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DomainException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class MailTemplateService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function latest(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, template_key, version, subject_template, html_template, text_template, '
            . 'allowed_placeholders, active, created_at FROM mail_templates ORDER BY template_key, version DESC'
        );
        if ($statement === false) {
            throw new RuntimeException('Die E-Mail-Templates konnten nicht geladen werden.');
        }

        $latest = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) $row['template_key'];
            if (isset($latest[$key])) {
                continue;
            }
            $latest[$key] = $row;
        }

        return array_values($latest);
    }

    /** @throws JsonException */
    public function createVersion(
        string $templateKey,
        string $subjectTemplate,
        string $htmlTemplate,
        string $textTemplate,
        bool $active,
        int $staffUserId,
    ): int {
        $templateKey = trim($templateKey);
        $subjectTemplate = trim($subjectTemplate);
        $htmlTemplate = trim($htmlTemplate);
        $textTemplate = trim($textTemplate);
        if (!preg_match('/^[a-z0-9_]{3,64}$/', $templateKey)) {
            throw new DomainException('Der E-Mail-Template-Schlüssel ist ungültig.');
        }
        if ($subjectTemplate === '' || mb_strlen($subjectTemplate) > 500) {
            throw new DomainException('Der E-Mail-Betreff ist ungültig.');
        }
        if ($htmlTemplate === '' || $textTemplate === '') {
            throw new DomainException('HTML- und Textfassung müssen vorhanden sein.');
        }
        if ($staffUserId < 1) {
            throw new DomainException('Der bearbeitende Benutzer ist ungültig.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT version, allowed_placeholders FROM mail_templates '
                . 'WHERE template_key = :template_key ORDER BY version DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['template_key' => $templateKey]);
            $current = $statement->fetch();
            if (!is_array($current)) {
                throw new DomainException('Das E-Mail-Template existiert nicht.');
            }

            $allowed = json_decode((string) $current['allowed_placeholders'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($allowed)) {
                throw new RuntimeException('Die Platzhalterdefinition des E-Mail-Templates ist ungültig.');
            }
            $allowedList = [];
            foreach ($allowed as $placeholder) {
                if (!is_string($placeholder)) {
                    throw new RuntimeException('Die Platzhalterdefinition des E-Mail-Templates ist ungültig.');
                }
                $allowedList[] = $placeholder;
            }
            $this->assertOnlyAllowedPlaceholders($subjectTemplate, $htmlTemplate, $textTemplate, $allowedList);

            if ($active) {
                $disable = $this->pdo->prepare(
                    'UPDATE mail_templates SET active = 0 WHERE template_key = :template_key AND active = 1'
                );
                $disable->execute(['template_key' => $templateKey]);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO mail_templates '
                . '(template_key, version, subject_template, html_template, text_template, allowed_placeholders, '
                . 'active, created_by_staff_user_id, created_at) VALUES '
                . '(:template_key, :version, :subject_template, :html_template, :text_template, '
                . ':allowed_placeholders, :active, :staff_user_id, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'template_key' => $templateKey,
                'version' => (int) $current['version'] + 1,
                'subject_template' => $subjectTemplate,
                'html_template' => $htmlTemplate,
                'text_template' => $textTemplate,
                'allowed_placeholders' => json_encode($allowedList, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'active' => $active ? 1 : 0,
                'staff_user_id' => $staffUserId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Die neue E-Mail-Template-Version konnte nicht angelegt werden.');
            }
            $this->pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param list<string> $allowed */
    private function assertOnlyAllowedPlaceholders(
        string $subject,
        string $html,
        string $text,
        array $allowed,
    ): void {
        $allowedMap = array_fill_keys($allowed, true);
        foreach ([$subject, $html, $text] as $template) {
            preg_match_all('/\{\{([a-z0-9_]+)\}\}/i', $template, $matches);
            foreach ($matches[1] as $placeholder) {
                if (!isset($allowedMap[$placeholder])) {
                    throw new DomainException('Das Template verwendet einen nicht erlaubten Platzhalter.');
                }
            }
        }
    }
}
