<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DomainException;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class PhpMailerSmtpSender implements MailSender
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $this->assertConfigured();

        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $this->host;
            $mailer->Port = $this->port;
            $mailer->SMTPAuth = $this->username !== '';
            if ($mailer->SMTPAuth) {
                $mailer->Username = $this->username;
                $mailer->Password = $this->password;
            }
            $mailer->CharSet = 'UTF-8';
            $mailer->Timeout = 20;
            $mailer->SMTPKeepAlive = false;

            $encryption = strtolower(trim($this->encryption));
            if ($encryption === 'tls' || $encryption === 'starttls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl' || $encryption === 'smtps') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption !== '' && $encryption !== 'none') {
                throw new DomainException('Die SMTP-Verschlüsselung ist ungültig.');
            }

            $mailer->setFrom($this->fromEmail, $this->fromName);
            $mailer->addAddress($message->recipientEmail, $message->recipientName ?? '');
            $mailer->Subject = $message->subject;
            $mailer->isHTML(true);
            $mailer->Body = $message->htmlBody;
            $mailer->AltBody = $message->textBody;
            $mailer->send();
        } catch (PhpMailerException $exception) {
            throw new RuntimeException('SMTP-Versand fehlgeschlagen: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function assertConfigured(): void
    {
        if (trim($this->host) === '' || $this->port < 1 || $this->port > 65535) {
            throw new DomainException('SMTP ist noch nicht vollständig konfiguriert.');
        }
        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Die SMTP-Absenderadresse ist ungültig.');
        }
    }
}
