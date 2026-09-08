<?php

declare(strict_types=1);

namespace FachDock\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LoggerFactory
{
    public static function create(string $root, string $channel = 'app'): LoggerInterface
    {
        $directory = $root . '/storage/logs';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create log directory.');
        }

        $logger = new Logger($channel);
        $logger->pushHandler(new RotatingFileHandler(
            $directory . '/' . $channel . '.log',
            14,
            Level::Info,
            true,
            0660,
        ));

        return $logger;
    }
}
