<?php

declare(strict_types=1);

namespace FachDock\Migration;

use PDO;

interface Migration
{
    public function version(): string;

    public function description(): string;

    public function up(PDO $pdo): void;
}
