<?php

declare(strict_types=1);

namespace FachDock\Booking;

use FachDock\Config\Config;
use FachDock\Database\ConnectionFactory;
use FachDock\Http\Request;
use FachDock\Http\Response;
use FachDock\Installation\InstallationState;
use FachDock\Parent\ParentSessionService;
use PDO;

final class BookingDocumentFrontController
{
    private const PATH = '/parent/bookings/document';

    public static function handle(string $root): ?Response
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== self::PATH) {
            return null;
        }
        if (!(new InstallationState($root))->isInstalled()) {
            return null;
        }
        self::startSession();
        $config = Config::load($root);
        $pdo = ConnectionFactory::fromConfig($config);
        $sessions = new ParentSessionService(
            $pdo,
            self::positiveInt($config, 'auth.parent_session_lifetime_minutes', 1440),
            self::positiveInt($config, 'auth.session_idle_timeout_minutes', 60),
        );
        $parent = $sessions->current();
        if ($parent === null) {
            return Response::redirect('/parent/login');
        }

        $request = Request::fromGlobals();
        if ($request->method() !== 'GET') {
            return Response::html('<h1>405</h1><p>Methode nicht erlaubt.</p>', 405);
        }
        $bookingId = self::positiveId(self::queryString($request, 'booking_id'));
        if ($bookingId === null) {
            return Response::html('<h1>Buchung nicht gefunden</h1>', 404);
        }

        $booking = self::booking($pdo, $parent->id, $bookingId);
        if ($booking === null) {
            return Response::html('<h1>Buchung nicht gefunden</h1>', 404);
        }
        $payment = self::latestPayment($pdo, $parent->id, $bookingId);
        $schoolName = trim((string) $config->get('app.school_name', ''));
        $title = $payment !== null && (string) $payment['status'] === 'paid'
            ? 'Buchungsbestätigung und Zahlungsbeleg'
            : 'Buchungsbestätigung';
        $lines = [
            ($schoolName !== '' ? $schoolName : 'FachDock'),
            'Dokument: FD-B' . $bookingId,
            'Erstellt: ' . date('d.m.Y H:i'),
            '',
            'Buchung: #' . $bookingId,
            'Schüler/in: ' . (string) $booking['first_name'] . ' ' . (string) $booking['last_name'],
            'Klasse: ' . (string) $booking['class_name'],
            'Schuljahr: ' . (string) $booking['school_year_label'],
            'Schließfach: ' . (string) ($booking['locker_short_name'] ?? '–'),
            'Standort: ' . self::location($booking),
            'Laufzeit: ' . self::date((string) $booking['valid_from']) . ' bis ' . self::date((string) $booking['valid_until']),
            'Buchungsstatus: ' . self::bookingStatus((string) $booking['status']),
            '',
            'Jahresbeitrag: ' . self::money((int) $booking['annual_fee_cents']),
            'Berechneter Beitrag: ' . self::money((int) $booking['charged_fee_cents']),
            'Berechnungsmonate: ' . (int) $booking['proration_months'],
            'Gebührenbefreiung: ' . self::exemption((string) ($booking['fee_exemption_type'] ?? '')),
        ];

        if ($payment !== null) {
            $lines[] = '';
            $lines[] = 'Zahlung: #' . (int) $payment['id'];
            $lines[] = 'Zahlungsstatus: ' . self::paymentStatus((string) $payment['status']);
            $lines[] = 'Zahlungsbetrag: ' . self::money((int) $payment['amount_cents']);
            if ($payment['paid_at'] !== null) {
                $lines[] = 'Bezahlt am: ' . self::dateTime((string) $payment['paid_at']);
            }
            if ($payment['stripe_payment_intent_id'] !== null) {
                $lines[] = 'Zahlungsreferenz: ' . (string) $payment['stripe_payment_intent_id'];
            }
        }
        $lines[] = '';
        $lines[] = 'Dieser PDF-Beleg bestätigt die in FachDock gespeicherte Buchung und, sofern ausgewiesen, den Zahlungseingang.';
        $lines[] = 'Er ist keine steuerliche Rechnung mit Umsatzsteuerausweis.';

        $pdf = (new BookingReceiptPdf())->render($title, $lines);

        return Response::download($pdf, 'FachDock-Buchung-' . $bookingId . '.pdf', 'application/pdf');
    }

    /** @return array<string, mixed>|null */
    private static function booking(PDO $pdo, int $parentId, int $bookingId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT b.id, b.status, b.valid_from, b.valid_until, b.annual_fee_cents, b.charged_fee_cents, '
            . 'b.proration_months, b.fee_exemption_type, s.first_name, s.last_name, s.class_name, '
            . 'sy.label AS school_year_label, l.short_name AS locker_short_name, bu.name AS building_name, '
            . 'f.name AS floor_name, a.name AS area_name '
            . 'FROM bookings b '
            . 'INNER JOIN parent_student_link_slots psl ON psl.student_id = b.student_id '
            . 'INNER JOIN students s ON s.id = b.student_id '
            . 'INNER JOIN school_years sy ON sy.id = b.school_year_id '
            . 'LEFT JOIN locker_occupancies lo ON lo.booking_id = b.id '
            . 'LEFT JOIN lockers l ON l.id = lo.locker_id '
            . 'LEFT JOIN corpuses c ON c.id = l.corpus_id '
            . 'LEFT JOIN cabinet_groups cg ON cg.id = c.cabinet_group_id '
            . 'LEFT JOIN areas a ON a.id = cg.area_id '
            . 'LEFT JOIN floors f ON f.id = a.floor_id '
            . 'LEFT JOIN buildings bu ON bu.id = f.building_id '
            . 'WHERE b.id = :booking_id AND psl.parent_contact_id = :parent_id LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId, 'parent_id' => $parentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function latestPayment(PDO $pdo, int $parentId, int $bookingId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, status, amount_cents, paid_at, stripe_payment_intent_id FROM payments '
            . 'WHERE booking_id = :booking_id AND parent_contact_id = :parent_id '
            . 'ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['booking_id' => $bookingId, 'parent_id' => $parentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $booking */
    private static function location(array $booking): string
    {
        $parts = array_values(array_filter([
            trim((string) ($booking['building_name'] ?? '')),
            trim((string) ($booking['floor_name'] ?? '')),
            trim((string) ($booking['area_name'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        return $parts === [] ? '–' : implode(' · ', $parts);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' EUR';
    }

    private static function bookingStatus(string $status): string
    {
        return match ($status) {
            'active' => 'aktiv',
            'exemption_review' => 'BuT-Prüfung',
            'payment_due' => 'Zahlung offen',
            'ended' => 'beendet',
            'cancelled' => 'storniert',
            default => $status,
        };
    }

    private static function paymentStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'bezahlt',
            'checkout_open' => 'Checkout offen',
            'processing_paid' => 'wird verbucht',
            'manual_review' => 'manuelle Prüfung',
            'failed' => 'fehlgeschlagen',
            'expired' => 'beendet/abgelaufen',
            default => $status,
        };
    }

    private static function exemption(string $value): string
    {
        return match ($value) {
            'but' => 'BuT',
            'no_fee' => 'keine Gebühr',
            '' => 'keine',
            default => $value,
        };
    }

    private static function date(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('d.m.Y', $time);
    }

    private static function dateTime(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('d.m.Y H:i', $time);
    }

    private static function queryString(Request $request, string $key): string
    {
        $value = $request->query()[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function positiveId(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function positiveInt(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('fachdock');
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}
