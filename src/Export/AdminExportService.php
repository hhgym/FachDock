<?php

declare(strict_types=1);

namespace FachDock\Export;

use DomainException;
use PDO;
use RuntimeException;

final class AdminExportService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{filename:string,content:string} */
    public function export(string $type): array
    {
        return match ($type) {
            'bookings' => $this->bookings(),
            'payments' => $this->payments(),
            'incidents' => $this->incidents(),
            'audit' => $this->audit(),
            default => throw new DomainException('Dieser Exporttyp ist nicht verfügbar.'),
        };
    }

    /** @return array{filename:string,content:string} */
    private function bookings(): array
    {
        $rows = $this->query(
            'SELECT b.id, s.matrikelnummer, s.last_name, s.first_name, s.class_name, sy.label AS school_year, '
            . 'b.status, b.projected_grade, l.short_name AS locker, b.valid_from, b.valid_until, '
            . 'b.annual_fee_cents, b.charged_fee_cents, b.fee_exemption_type, b.created_at, b.ended_at '
            . 'FROM bookings b INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN lockers l ON l.id = lo.locker_id ORDER BY sy.starts_on DESC, s.last_name, s.first_name, b.id'
        );

        return $this->csv('fachdock-buchungen', [
            'Buchungs-ID', 'Matrikelnummer', 'Nachname', 'Vorname', 'Klasse', 'Schuljahr', 'Status', 'Stufe',
            'Schließfach', 'Gültig ab', 'Gültig bis', 'Jahresbeitrag Cent', 'Berechnet Cent', 'Befreiung',
            'Angelegt', 'Beendet',
        ], $rows);
    }

    /** @return array{filename:string,content:string} */
    private function payments(): array
    {
        $rows = $this->query(
            'SELECT p.id, p.provider, p.status, p.amount_cents, p.currency, p.booking_id, p.reservation_id, '
            . 'pc.email AS parent_email, pc.last_name AS parent_last_name, pc.first_name AS parent_first_name, '
            . 'p.stripe_checkout_session_id, p.stripe_payment_intent_id, p.failure_code, p.created_at, p.paid_at, p.failed_at '
            . 'FROM payments p INNER JOIN parent_contacts pc ON pc.id = p.parent_contact_id '
            . 'ORDER BY p.id DESC'
        );

        return $this->csv('fachdock-zahlungen', [
            'Zahlungs-ID', 'Anbieter', 'Status', 'Betrag Cent', 'Währung', 'Buchungs-ID', 'Reservierungs-ID',
            'Eltern-E-Mail', 'Eltern-Nachname', 'Eltern-Vorname', 'Checkout-Session', 'Payment-Intent', 'Fehlercode',
            'Angelegt', 'Bezahlt', 'Fehlgeschlagen',
        ], $rows);
    }

    /** @return array{filename:string,content:string} */
    private function incidents(): array
    {
        $rows = $this->query(
            'SELECT i.id, l.short_name, i.category, i.status, i.priority, s.matrikelnummer, s.last_name, s.first_name, '
            . 'i.reported_by_type, i.reporter_email, i.opened_at, i.resolved_at, i.description, i.resolution_note '
            . 'FROM locker_incidents i INNER JOIN lockers l ON l.id = i.locker_id '
            . 'LEFT JOIN students s ON s.id = i.student_id ORDER BY i.id DESC'
        );

        return $this->csv('fachdock-meldungen', [
            'Vorgangs-ID', 'Schließfach', 'Kategorie', 'Status', 'Priorität', 'Matrikelnummer', 'Nachname', 'Vorname',
            'Gemeldet durch', 'Melder-E-Mail', 'Geöffnet', 'Erledigt', 'Beschreibung', 'Ergebnis',
        ], $rows);
    }

    /** @return array{filename:string,content:string} */
    private function audit(): array
    {
        $rows = $this->query(
            'SELECT a.id, a.created_at, a.actor_type, su.username AS staff_username, pc.email AS parent_email, '
            . 'a.action, a.entity_type, a.entity_id, a.metadata '
            . 'FROM audit_log a LEFT JOIN staff_users su ON su.id = a.staff_user_id '
            . 'LEFT JOIN parent_contacts pc ON pc.id = a.parent_contact_id ORDER BY a.id DESC'
        );

        return $this->csv('fachdock-audit', [
            'Audit-ID', 'Zeit', 'Akteurtyp', 'Benutzer', 'Eltern-E-Mail', 'Aktion', 'Entität', 'Entitäts-ID', 'Metadaten',
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Der Export konnte nicht erstellt werden.');
        }

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, mixed>> $rows
     * @return array{filename:string,content:string}
     */
    private function csv(string $filenamePrefix, array $headers, array $rows): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Der CSV-Export konnte nicht erstellt werden.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers, ';', '"', '\\');
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? '' : (string) $value;
            }
            fputcsv($stream, $values, ';', '"', '\\');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($content)) {
            throw new RuntimeException('Der CSV-Export konnte nicht gelesen werden.');
        }

        return [
            'filename' => $filenamePrefix . '-' . date('Y-m-d-His') . '.csv',
            'content' => $content,
        ];
    }
}
