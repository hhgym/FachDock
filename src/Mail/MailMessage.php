<?php

declare(strict_types=1);

namespace FachDock\Mail;

final readonly class MailMessage
{
    public function __construct(
        public string $recipientEmail,
        public ?string $recipientName,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
    ) {
    }
}
