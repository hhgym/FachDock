<?php

declare(strict_types=1);

namespace FachDock\Mail;

interface MailSender
{
    public function send(MailMessage $message): void;
}
