<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DomainException;

final class MailTemplateRenderer
{
    /**
     * @param array<string, scalar|null> $placeholders
     * @param list<string> $allowedPlaceholders
     */
    public function render(
        string $subjectTemplate,
        string $htmlTemplate,
        string $textTemplate,
        array $placeholders,
        array $allowedPlaceholders,
    ): RenderedMail {
        $allowed = array_fill_keys($allowedPlaceholders, true);
        foreach (array_keys($placeholders) as $key) {
            if (!isset($allowed[$key])) {
                throw new DomainException('Unbekannter Platzhalter: ' . $key);
            }
        }

        foreach ($allowedPlaceholders as $key) {
            if (!array_key_exists($key, $placeholders)) {
                throw new DomainException('Der Platzhalter {{' . $key . '}} fehlt.');
            }
        }

        $subjectValues = [];
        $htmlValues = [];
        $textValues = [];
        foreach ($placeholders as $key => $value) {
            $token = '{{' . $key . '}}';
            $string = $value === null ? '' : (string) $value;
            $subjectValues[$token] = str_replace(["\r", "\n"], ' ', $string);
            $htmlValues[$token] = htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $textValues[$token] = $string;
        }

        $subject = trim(strtr($subjectTemplate, $subjectValues));
        if ($subject === '' || mb_strlen($subject) > 500) {
            throw new DomainException('Der gerenderte E-Mail-Betreff ist ungültig.');
        }

        return new RenderedMail(
            $subject,
            strtr($htmlTemplate, $htmlValues),
            strtr($textTemplate, $textValues),
        );
    }
}
