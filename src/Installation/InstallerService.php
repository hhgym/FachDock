<?php

declare(strict_types=1);

namespace FachDock\Installation;

use FachDock\Auth\PasswordHasher;
use FachDock\Database\ConnectionFactory;
use FachDock\Migration\MigrationRunner;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class InstallerService
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string, mixed> $input */
    public function install(array $input): void
    {
        $requirements = new SystemRequirements($this->root);
        if (!$requirements->allMet()) {
            throw new RuntimeException('Nicht alle Systemvoraussetzungen sind erfüllt.');
        }

        $database = $this->databaseSettings($input);
        $schoolName = $this->required($input, 'school_name', 'Schulname');
        $adminUsername = $this->required($input, 'admin_username', 'Administrator-Benutzername');
        $adminDisplayName = $this->required($input, 'admin_display_name', 'Administrator-Anzeigename');
        $adminEmail = $this->required($input, 'admin_email', 'Administrator-E-Mail');
        $adminPassword = $this->required($input, 'admin_password', 'Administrator-Passwort');

        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Die Administrator-E-Mail-Adresse ist ungültig.');
        }
        if (mb_strlen($adminPassword) < 12) {
            throw new RuntimeException('Das Administrator-Passwort muss mindestens 12 Zeichen lang sein.');
        }

        $pdo = ConnectionFactory::connect($database);
        (new MigrationRunner($pdo, $this->root . '/migrations'))->migrate();

        $hash = (new PasswordHasher())->hash($adminPassword);

        $pdo->beginTransaction();
        try {
            $adminId = $this->upsertAdministrator(
                $pdo,
                $adminUsername,
                $adminDisplayName,
                $adminEmail,
                $hash,
            );
            $this->setSetting($pdo, 'school.name', $schoolName);
            $this->writeAuditEntry($pdo, $adminId);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $appConfig = [
            'app' => [
                'installed' => true,
                'school_name' => $schoolName,
            ],
            'database' => [
                'host' => $database['host'],
                'port' => $database['port'],
                'name' => $database['name'],
                'username' => $database['username'],
                'charset' => 'utf8mb4',
            ],
        ];
        $secrets = [
            'database' => [
                'password' => $database['password'],
            ],
        ];

        $appTemp = $this->prepareConfigFile('app.local.php', $appConfig, 0640);
        $secretTemp = $this->prepareConfigFile('secrets.local.php', $secrets, 0600);

        $this->publishConfigFile($secretTemp, $this->root . '/config/secrets.local.php', 0600);
        $this->publishConfigFile($appTemp, $this->root . '/config/app.local.php', 0640);
    }

    /** @param array<string, mixed> $input
     *  @return array{host: string, port: int, name: string, username: string, password: string, charset: string}
     */
    private function databaseSettings(array $input): array
    {
        $portValue = $this->required($input, 'db_port', 'Datenbank-Port');
        if (!ctype_digit($portValue)) {
            throw new RuntimeException('Der Datenbank-Port muss numerisch sein.');
        }
        $port = (int) $portValue;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Der Datenbank-Port liegt außerhalb des gültigen Bereichs.');
        }

        return [
            'host' => $this->required($input, 'db_host', 'Datenbank-Host'),
            'port' => $port,
            'name' => $this->required($input, 'db_name', 'Datenbankname'),
            'username' => $this->required($input, 'db_username', 'Datenbank-Benutzername'),
            'password' => $this->scalar($input, 'db_password'),
            'charset' => 'utf8mb4',
        ];
    }

    /** @param array<string, mixed> $input */
    private function required(array $input, string $key, string $label): string
    {
        $value = $this->scalar($input, $key);
        if ($value === '') {
            throw new RuntimeException($label . ' darf nicht leer sein.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function scalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function upsertAdministrator(
        PDO $pdo,
        string $username,
        string $displayName,
        string $email,
        string $passwordHash,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO staff_users (username, display_name, email, password_hash, role, active, created_at, updated_at) '
            . 'VALUES (:username, :display_name, :email, :password_hash, :role, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), email = VALUES(email), '
            . 'password_hash = VALUES(password_hash), role = VALUES(role), active = 1, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'administrator',
        ]);

        $lookup = $pdo->prepare('SELECT id FROM staff_users WHERE username = :username');
        $lookup->execute(['username' => $username]);
        $id = $lookup->fetchColumn();
        if (!is_int($id) && !is_string($id)) {
            throw new RuntimeException('Administrator konnte nicht angelegt werden.');
        }

        return (int) $id;
    }

    /** @throws JsonException */
    private function setSetting(PDO $pdo, string $key, mixed $value): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @throws JsonException */
    private function writeAuditEntry(PDO $pdo, int $adminId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO audit_log (actor_type, staff_user_id, action, entity_type, entity_id, metadata, created_at) '
            . 'VALUES (:actor_type, :staff_user_id, :action, :entity_type, :entity_id, :metadata, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'actor_type' => 'staff',
            'staff_user_id' => $adminId,
            'action' => 'system.installation.completed',
            'entity_type' => 'system',
            'entity_id' => 'fachdock',
            'metadata' => json_encode(['version' => '0.1.0-dev'], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function prepareConfigFile(string $name, array $values, int $mode): string
    {
        $directory = $this->root . '/config';
        $temp = $directory . '/.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";

        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Lokale Konfiguration konnte nicht vorbereitet werden.');
        }
        @chmod($temp, $mode);

        return $temp;
    }

    private function publishConfigFile(string $temp, string $target, int $mode): void
    {
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException('Lokale Konfiguration konnte nicht veröffentlicht werden.');
        }
        @chmod($target, $mode);
    }
}
