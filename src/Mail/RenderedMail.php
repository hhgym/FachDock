<?php

declare(strict_types=1);

namespace FachDock\Mail;

final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $htmlBody,
        public string $textBody,
    ) {
    }
}
