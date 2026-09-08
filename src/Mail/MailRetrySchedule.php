<?php

declare(strict_types=1);

namespace FachDock\Mail;

use DomainException;

final readonly class MailRetrySchedule
{
    /** @var list<int> */
    private array $minutes;

    /** @param list<int> $minutes */
    public function __construct(array $minutes = [15, 60, 360])
    {
        foreach ($minutes as $minute) {
            if ($minute < 1 || $minute > 10080) {
                throw new DomainException('Eine E-Mail-Wiederholungsfrist ist ungültig.');
            }
        }
        $this->minutes = $minutes;
    }

    public function delayAfterFailure(int $attempts): ?int
    {
        if ($attempts < 1) {
            throw new DomainException('Die Anzahl der Versandversuche ist ungültig.');
        }

        return $this->minutes[$attempts - 1] ?? null;
    }
}
