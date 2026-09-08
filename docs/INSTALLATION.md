# Installation

> Version 0.1.x ist ein früher Teststand vor 1.0.0 und noch nicht für den produktiven Schulbetrieb vorgesehen.

## Voraussetzungen

- PHP 8.3+
- PHP-Erweiterungen: PDO, pdo_mysql, JSON, mbstring, OpenSSL, cURL, ZIP
- MariaDB 10.6+ oder MySQL 8.0+
- Apache 2.4+ oder nginx
- HTTPS für produktionsnahe Tests

Der Document Root des Webservers muss auf `public/` zeigen. Anwendungsquellcode, lokale Konfiguration, `vendor/` und `storage/` liegen damit außerhalb des öffentlich erreichbaren Webroots.

## Web-Installer

1. Eine leere Datenbank und einen Datenbankbenutzer mit Rechten für diese Datenbank anlegen.
2. Das FachDock-Release-Paket entpacken.
3. Den Document Root des Webservers auf `public/` setzen.
4. `config/` und `storage/` für den PHP-/Webserver-Prozess beschreibbar machen.
5. FachDock im Browser öffnen. Eine nicht installierte Instanz leitet automatisch auf `/install` weiter.
6. Datenbankdaten, Schulname und das erste Administratorkonto eingeben.
7. Der Installer führt alle ausstehenden Migrationen aus und erzeugt `config/app.local.php` sowie `config/secrets.local.php`.

`config/secrets.local.php` darf niemals in Git eingecheckt werden.

## Integrierte Updates

Für den Update-Test muss zusätzlich das FachDock-Installationsverzeichnis für den Webserver-Prozess beschreibbar sein, weil Anwendungsdateien ersetzt werden. Lokale Konfiguration und `storage/` werden durch das Update nicht überschrieben.

Administratoren finden die Updateprüfung unter **Updates**. FachDock berücksichtigt ausschließlich stabile GitHub Releases. Vor der Installation wird das Release-ZIP anhand der veröffentlichten SHA-256-Prüfsumme geprüft. Während des Dateiaustauschs und der Migrationen wird der Wartungsmodus aktiviert.

Für produktive Installationen soll die spätere Betriebsdokumentation restriktivere Dateirechte und ein kontrolliertes Updateverfahren beschreiben; die derzeitige Schreibberechtigung des Installationsverzeichnisses dient ausdrücklich dem frühen Update-Test.

## Apache

`public/.htaccess` enthält die Rewrite-Regeln. `mod_rewrite` muss aktiviert sein und Overrides müssen für den Document Root erlaubt werden.

## nginx

Ein minimaler Location-Block ist:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Die PHP-FPM-Konfiguration ist hostingabhängig.
