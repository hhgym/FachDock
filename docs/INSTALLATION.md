# Installation

> FachDock is under active development. These instructions describe the intended installation path for the first development release.

## Requirements

- PHP 8.3+
- PHP extensions: PDO, pdo_mysql, JSON, mbstring, OpenSSL
- MariaDB 10.6+ or MySQL 8.0+
- Apache 2.4+ or nginx
- HTTPS for production use

The web server document root must point to `public/`.

## Web installer

1. Create an empty database and a database user with privileges for that database.
2. Extract a FachDock release package.
3. Point the web server document root to `public/`.
4. Ensure `config/` and `storage/` are writable by the PHP/web-server process during installation.
5. Open FachDock in the browser. Uninstalled instances redirect to `/install`.
6. Enter database, school and first-administrator details.
7. The installer runs pending migrations and creates `config/app.local.php` and `config/secrets.local.php`.

`config/secrets.local.php` must never be committed to Git.

## Apache

`public/.htaccess` contains the rewrite rules. `mod_rewrite` must be enabled and overrides must be allowed for the document root.

## nginx

A minimal location block is:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

The PHP-FPM configuration is hosting-specific.
