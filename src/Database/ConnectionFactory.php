<?php

declare(strict_types=1);

namespace FachDock\Database;

use FachDock\Config\Config;
use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public static function fromConfig(Config $config): PDO
    {
        return self::connect([
            'host' => $config->get('database.host'),
            'port' => $config->get('database.port'),
            'name' => $config->get('database.name'),
            'username' => $config->get('database.username'),
            'password' => $config->get('database.password', ''),
            'charset' => $config->get('database.charset', 'utf8mb4'),
        ]);
    }

    /** @param array<string, mixed> $settings */
    public static function connect(array $settings): PDO
    {
        $host = self::requiredString($settings, 'host');
        $name = self::requiredString($settings, 'name');
        $username = self::requiredString($settings, 'username');
        $password = isset($settings['password']) && is_scalar($settings['password']) ? (string) $settings['password'] : '';
        $port = isset($settings['port']) ? (int) $settings['port'] : 3306;
        $charset = isset($settings['charset']) && is_scalar($settings['charset']) ? (string) $settings['charset'] : 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @param array<string, mixed> $settings */
    private static function requiredString(array $settings, string $key): string
    {
        $value = $settings[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException('Missing database setting: ' . $key);
        }

        return trim((string) $value);
    }
}
