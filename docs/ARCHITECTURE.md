# FachDock Architecture

This document records the high-level technical architecture of FachDock.

## Runtime

- PHP 8.3+
- MariaDB 10.6+ or MySQL 8.0+
- InnoDB, utf8mb4
- Apache 2.4+ or nginx
- HTTPS required in production

## Application layout

- `public/` – only web-accessible directory
- `src/` – application source code
- `templates/` – server-rendered PHP templates
- `config/` – non-public configuration
- `migrations/` – versioned database migrations
- `storage/` – runtime data, generated documents and logs
- `tests/` – automated tests

## Design principles

- server-side rendering with progressive enhancement
- explicit service and repository layers
- PDO and database transactions for booking-critical operations
- migrations for all schema changes
- secrets outside the database in `config/secrets.local.php`
- stable domain identifiers and auditable state transitions
- Git Flow with stable releases from `main`
