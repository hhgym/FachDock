<?php

declare(strict_types=1);

namespace FachDock\System;

use FachDock\Config\Config;
use FachDock\Payment\StripeConfigurationState;
use PDO;

final class ProductionReadinessService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly string $root,
    ) {
    }

    /** @return array{ready:bool,failures:int,warnings:int,checks:list<array{id:string,label:string,status:string,detail:string}>} */
    public function snapshot(): array
    {
        $checks = [];
        $environment = (string) $this->config->get('app.environment', 'production');
        $checks[] = $this->check(
            'environment',
            'Produktionsumgebung',
            $environment === 'production' ? 'ok' : 'warning',
            $environment === 'production' ? 'app.environment = production' : 'Aktuell: ' . $environment,
        );
        $debug = $this->config->get('app.debug', false) === true;
        $checks[] = $this->check(
            'debug',
            'Debug-Modus deaktiviert',
            !$debug ? 'ok' : 'fail',
            !$debug ? 'Keine technischen Fehlerdetails werden öffentlich ausgegeben.' : 'app.debug muss für den Produktivbetrieb false sein.',
        );
        $baseUrl = rtrim(trim((string) $this->config->get('app.base_url', '')), '/');
        $validHttps = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($baseUrl), 'https://');
        $checks[] = $this->check(
            'https',
            'Öffentliche HTTPS-Basis-URL',
            $validHttps ? 'ok' : 'fail',
            $validHttps ? $baseUrl : 'Eine öffentliche HTTPS-Basis-URL ist erforderlich.',
        );

        $smtpHost = trim((string) $this->config->get('smtp.host', ''));
        $smtpFrom = trim((string) $this->config->get('smtp.from_email', ''));
        $smtpUser = trim((string) $this->config->get('smtp.username', ''));
        $smtpPassword = (string) $this->config->get('smtp.password', '');
        $smtpOk = $smtpHost !== '' && filter_var($smtpFrom, FILTER_VALIDATE_EMAIL) !== false
            && ($smtpUser === '' || $smtpPassword !== '');
        $checks[] = $this->check(
            'smtp',
            'SMTP vollständig konfiguriert',
            $smtpOk ? 'ok' : 'fail',
            $smtpOk ? $smtpHost . ' · ' . $smtpFrom : 'Magic Links und Benachrichtigungen benötigen einen funktionierenden SMTP-Versand.',
        );

        $worker = $this->workerAge('mail', 'MINUTE');
        $checks[] = $this->check(
            'mail_worker',
            'Mail-Worker aktuell',
            $worker !== null && $worker <= 60 ? 'ok' : 'fail',
            $worker === null ? 'Noch kein erfolgreicher Mail-Worker-Lauf protokolliert.' : 'Letzter Erfolg vor ' . $worker . ' Minute(n).',
        );
        $yearWorker = $this->workerAge('school_year', 'HOUR');
        $checks[] = $this->check(
            'school_year_worker',
            'Schuljahresjob aktuell',
            $yearWorker !== null && $yearWorker <= 36 ? 'ok' : 'warning',
            $yearWorker === null ? 'Noch kein erfolgreicher school-year:tick-Lauf protokolliert.' : 'Letzter Erfolg vor ' . $yearWorker . ' Stunde(n).',
        );
        $privacyWorker = $this->workerAge('privacy', 'HOUR');
        $checks[] = $this->check(
            'privacy_worker',
            'Datenschutzjob aktuell',
            $privacyWorker !== null && $privacyWorker <= 8 * 24 ? 'ok' : 'warning',
            $privacyWorker === null
                ? 'Noch kein erfolgreicher privacy:tick-Lauf protokolliert.'
                : 'Letzter Erfolg vor ' . $privacyWorker . ' Stunde(n).',
        );

        $backup = $this->latestBackup();
        $backupOk = $backup['hours'] !== null && $backup['hours'] <= 36 && $backup['checksum'];
        $backupDetail = 'Noch kein Vollbackup unter storage/backups gefunden.';
        if ($backup['file'] !== null) {
            $backupDetail = $backup['file'] . ' · '
                . ($backup['hours'] === null ? 'Alter unbekannt' : 'vor ' . $backup['hours'] . ' Stunde(n)')
                . ' · Prüfsumme ' . ($backup['checksum'] ? 'vorhanden' : 'fehlt');
        }
        $checks[] = $this->check(
            'backup',
            'Aktuelles Vollbackup',
            $backupOk ? 'ok' : 'warning',
            $backupDetail,
        );

        $storageOk = is_dir($this->root . '/storage') && is_writable($this->root . '/storage');
        $configOk = is_dir($this->root . '/config') && is_writable($this->root . '/config');
        $checks[] = $this->check(
            'filesystem',
            'Lokale Schreibrechte',
            $storageOk && $configOk ? 'ok' : 'fail',
            'Storage: ' . ($storageOk ? 'beschreibbar' : 'nicht beschreibbar') . '; Konfiguration: ' . ($configOk ? 'beschreibbar' : 'nicht beschreibbar'),
        );

        $adminCount = $this->count("SELECT COUNT(*) FROM staff_users WHERE active = 1 AND role = 'administrator'");
        $checks[] = $this->check(
            'administrators',
            'Aktives Administratorkonto',
            $adminCount >= 1 ? 'ok' : 'fail',
            $adminCount . ' aktives Administratorkonto/aktive Administratorkonten.',
        );

        $paidYears = $this->count('SELECT COUNT(*) FROM school_years WHERE annual_fee_cents > 0');
        $stripe = StripeConfigurationState::fromConfig($this->config);
        $stripeStatus = $paidYears === 0 || $stripe->checkoutAvailable() ? 'ok' : 'fail';
        $checks[] = $this->check(
            'stripe',
            'Zahlungsweg für kostenpflichtige Schuljahre',
            $stripeStatus,
            $paidYears === 0
                ? 'Aktuell ist kein Schuljahr mit Jahresbeitrag konfiguriert.'
                : ($stripe->checkoutAvailable() ? 'Stripe Checkout ist verfügbar.' : implode(' ', $stripe->problems())),
        );

        $failures = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        $warnings = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warning'));

        return ['ready' => $failures === 0, 'failures' => $failures, 'warnings' => $warnings, 'checks' => $checks];
    }

    /** @return array{id:string,label:string,status:string,detail:string} */
    private function check(string $id, string $label, string $status, string $detail): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function count(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        $value = $statement === false ? false : $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function workerAge(string $key, string $unit): ?int
    {
        if (!in_array($unit, ['MINUTE', 'HOUR'], true)) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT TIMESTAMPDIFF(' . $unit . ', last_success_at, CURRENT_TIMESTAMP) '
            . 'FROM system_worker_status WHERE worker_key = :key'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    /** @return array{file:?string,hours:?int,checksum:bool} */
    private function latestBackup(): array
    {
        $files = glob($this->root . '/storage/backups/fachdock-backup-*.zip');
        if (!is_array($files) || $files === []) {
            return ['file' => null, 'hours' => null, 'checksum' => false];
        }
        usort($files, static fn (string $a, string $b): int => ((int) filemtime($b)) <=> ((int) filemtime($a)));
        $latest = $files[0];
        $modified = filemtime($latest);
        $hours = $modified === false ? null : max(0, (int) floor((time() - $modified) / 3600));

        return [
            'file' => basename($latest),
            'hours' => $hours,
            'checksum' => is_file($latest . '.sha256'),
        ];
    }
}
