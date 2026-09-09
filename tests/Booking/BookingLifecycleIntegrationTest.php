<?php

declare(strict_types=1);

namespace FachDock\Tests\Booking;

use DomainException;
use FachDock\Auth\AuthenticatedStaff;
use FachDock\Auth\StaffRole;
use FachDock\Booking\AllocationRuleEvaluator;
use FachDock\Booking\BookingLifecycleService;
use FachDock\Mail\BookingLifecycleNotificationService;
use FachDock\Mail\MailQueueService;
use FachDock\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BookingLifecycleIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?string $databaseName = null;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('TEST_DB_HOST is not configured.');
        }
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $prefix = (string) (getenv('TEST_DB_NAME_PREFIX') ?: 'fachdock_test');

        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->databaseName = $prefix . '_lifecycle_' . bin2hex(random_bytes(4));
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $this->databaseName . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        (new MigrationRunner($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->databaseName === null) {
            return;
        }
        $host = (string) getenv('TEST_DB_HOST');
        $port = (string) (getenv('TEST_DB_PORT') ?: '3306');
        $username = (string) (getenv('TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TEST_DB_PASSWORD') ?: '');
        $this->pdo = null;
        $admin = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $admin->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
        $this->databaseName = null;
    }

    public function testLockerChangeIsAtomicAndNotifiesParent(): void
    {
        $service = $this->service();
        $service->changeLocker($this->staff(), 1, 2, 'Fach 1 ist defekt.');
        $this->notifications()->lockerChanged(1);

        self::assertSame(2, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = 1')->fetchColumn());
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM locker_assignment_history WHERE booking_id = 1 AND locker_id = 1 AND ends_at IS NOT NULL")->fetchColumn());
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM locker_assignment_history WHERE booking_id = 1 AND locker_id = 2 AND ends_at IS NULL")->fetchColumn());
        self::assertSame('locker_changed', $this->pdo()->query('SELECT event_type FROM booking_lifecycle_events WHERE booking_id = 1')->fetchColumn());
        self::assertSame(1, $this->queuedTemplateCount('booking_locker_changed'));
    }

    public function testEndingBookingReleasesSlotsAndCancelsPaymentReminder(): void
    {
        $this->queuePaymentReminder();
        $service = $this->service();
        $service->end($this->staff(), 1, 'Schüler verlässt die Schule.');
        $this->notifications()->ended(1, false);

        self::assertSame('ended', $this->pdo()->query('SELECT status FROM bookings WHERE id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM locker_occupancies WHERE booking_id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM booking_slots WHERE booking_id = 1')->fetchColumn());
        self::assertSame('canceled', $this->pdo()->query("SELECT q.status FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id WHERE t.template_key = 'payment_due_reminder' LIMIT 1")->fetchColumn());
        self::assertSame(1, $this->queuedTemplateCount('booking_ended'));
    }

    public function testCancellationPreservesContractualDateRange(): void
    {
        $before = $this->row('SELECT valid_from, valid_until FROM bookings WHERE id = 1');

        $this->service()->end($this->staff(), 1, 'Buchung wurde vorzeitig storniert.', true);
        $this->notifications()->ended(1, true);

        $after = $this->row('SELECT status, valid_from, valid_until FROM bookings WHERE id = 1');
        self::assertSame('cancelled', $after['status']);
        self::assertSame($before['valid_from'], $after['valid_from']);
        self::assertSame($before['valid_until'], $after['valid_until']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM locker_occupancies WHERE booking_id = 1')->fetchColumn());
        self::assertSame('cancelled', $this->pdo()->query('SELECT event_type FROM booking_lifecycle_events WHERE booking_id = 1')->fetchColumn());
        self::assertSame(1, $this->queuedTemplateCount('booking_cancelled'));
    }

    public function testRenewalCreatesLinkedPaymentDueBookingForNextYear(): void
    {
        $newBookingId = $this->service()->renew($this->staff(), 1, 2, false);
        $this->notifications()->renewed($newBookingId);

        $booking = $this->row('SELECT school_year_id, status, projected_grade, previous_booking_id, charged_fee_cents, proration_months FROM bookings WHERE id = ' . $newBookingId);
        self::assertSame(2, (int) $booking['school_year_id']);
        self::assertSame('payment_due', $booking['status']);
        self::assertSame(10, (int) $booking['projected_grade']);
        self::assertSame(1, (int) $booking['previous_booking_id']);
        self::assertSame(3000, (int) $booking['charged_fee_cents']);
        self::assertSame(12, (int) $booking['proration_months']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT locker_id FROM locker_occupancies WHERE booking_id = ' . $newBookingId)->fetchColumn());
        self::assertSame($newBookingId, (int) $this->pdo()->query("SELECT related_booking_id FROM booking_lifecycle_events WHERE booking_id = 1 AND event_type = 'renewed'")->fetchColumn());
        self::assertSame(1, $this->queuedTemplateCount('booking_renewed'));
    }

    public function testInFlightBookingPaymentBlocksTermination(): void
    {
        $this->pdo()->exec(
            "INSERT INTO payments (id, reservation_id, booking_id, parent_contact_id, provider, status, amount_cents, currency, annual_fee_cents, proration_months, created_at, updated_at) VALUES (1, NULL, 1, 1, 'stripe', 'checkout_open', 2400, 'EUR', 2400, 12, NOW(), NOW())"
        );
        $this->pdo()->exec('INSERT INTO booking_payment_attempt_slots (booking_id, payment_id, created_at) VALUES (1, 1, NOW())');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('laufenden Zahlungsvorgangs');
        $this->service()->end($this->staff(), 1, 'Soll blockiert werden.');
    }

    private function service(): BookingLifecycleService
    {
        return new BookingLifecycleService($this->pdo(), new AllocationRuleEvaluator($this->pdo()), 14);
    }

    private function notifications(): BookingLifecycleNotificationService
    {
        return new BookingLifecycleNotificationService(
            $this->pdo(),
            new MailQueueService($this->pdo()),
            'https://fachdock.test',
            'Heinrich-Hertz-Gymnasium',
            new NullLogger(),
        );
    }

    private function staff(): AuthenticatedStaff
    {
        return new AuthenticatedStaff(1, 'admin', 'Admin', 'admin@example.test', StaffRole::Administrator, 1);
    }

    private function pdo(): PDO
    {
        self::assertInstanceOf(PDO::class, $this->pdo);

        return $this->pdo;
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo()->query($sql)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function queuedTemplateCount(string $templateKey): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM mail_queue q INNER JOIN mail_templates t ON t.id = q.mail_template_id WHERE t.template_key = :key'
        );
        $statement->execute(['key' => $templateKey]);

        return (int) $statement->fetchColumn();
    }

    private function queuePaymentReminder(): void
    {
        (new MailQueueService($this->pdo()))->enqueue(
            'payment_due_reminder',
            'parent@example.test',
            'Erika Muster',
            [
                'school_name' => 'Schule',
                'parent_name_suffix' => ' Erika Muster',
                'student_name' => 'Max Muster',
                'amount' => '24,00 €',
                'payment_due_at' => '30.09.2026',
                'booking_url' => 'https://fachdock.test/parent/booking/status?booking_id=1',
            ],
            [],
            'booking',
            1,
            'payment-due-reminder:1',
            'payment-due-reminder:1',
            60,
        );
    }

    private function seed(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO buildings (id, code, name, active, created_at, updated_at) VALUES (1, 'A', 'Haus A', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO floors (id, building_id, code, name, sort_order, active, created_at, updated_at) VALUES (1, 1, 'EG', 'Erdgeschoss', 0, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO areas (id, floor_id, code, name, active, created_at, updated_at) VALUES (1, 1, 'N', 'Nord', 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO corpus_types (id, code, name, compartment_count, active, created_at, updated_at) VALUES (1, 'T2', 'Zweier', 2, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO cabinet_groups (id, area_id, code, active, created_at, updated_at) VALUES (1, 1, 'A', 1, NOW(), NOW())");
        $pdo->exec('INSERT INTO corpuses (id, cabinet_group_id, corpus_type_id, position_no, active, created_at, updated_at) VALUES (1, 1, 1, 1, 1, NOW(), NOW())');
        $pdo->exec("INSERT INTO lockers (id, corpus_id, position_no, short_name, barrier_friendly, bookable, active, operating_status, created_at, updated_at) VALUES (1, 1, 1, 'A-01-1', 0, 1, 1, 'operational', NOW(), NOW()), (2, 1, 2, 'A-01-2', 0, 1, 1, 'operational', NOW(), NOW())");
        $pdo->exec("INSERT INTO students (id, matrikelnummer, first_name, last_name, class_name, grade, active, created_at, updated_at) VALUES (1, '1001', 'Max', 'Muster', '8-1', 8, 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO school_years (id, label, starts_on, ends_on, status, new_booking_opens_on, annual_fee_cents, created_at, updated_at) VALUES (1, '2026/27', '2026-08-01', '2027-07-31', 'current', '2026-01-01', 2400, NOW(), NOW()), (2, '2027/28', '2027-08-01', '2028-07-31', 'future', '2027-01-01', 3000, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_contacts (id, email, first_name, last_name, status, verified_at, active, created_at, updated_at) VALUES (1, 'parent@example.test', 'Erika', 'Muster', 'verified', NOW(), 1, NOW(), NOW())");
        $pdo->exec("INSERT INTO parent_student_links (id, parent_contact_id, student_id, link_origin, started_at, created_at) VALUES (1, 1, 1, 'staff', NOW(), NOW())");
        $pdo->exec('INSERT INTO parent_student_link_slots (parent_contact_id, student_id, link_id, created_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec("INSERT INTO bookings (id, student_id, school_year_id, status, projected_grade, valid_from, valid_until, initiated_by_type, initiated_by_id, annual_fee_cents, charged_fee_cents, proration_months, fee_exemption_type, student_snapshot, rule_snapshot, created_at, updated_at) VALUES (1, 1, 1, 'active', 9, '2026-08-01', '2027-07-31', 'parent', 1, 2400, 2400, 12, NULL, '{}', '{}', NOW(), NOW())");
        $pdo->exec('INSERT INTO booking_slots (booking_id, school_year_id, student_id, created_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec('INSERT INTO locker_occupancies (school_year_id, locker_id, booking_id, assigned_at) VALUES (1, 1, 1, NOW())');
        $pdo->exec("INSERT INTO locker_assignment_history (booking_id, school_year_id, locker_id, starts_at, reason, actor_type, actor_id, locker_snapshot, created_at) VALUES (1, 1, 1, NOW(), 'initial_booking', 'parent', 1, '{}', NOW())");
    }
}
