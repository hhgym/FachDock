# Installation

> Version `1.0.0-rc.1` ist ein Release Candidate für die produktionsnahe Abnahme vor FachDock 1.0.0. Sie ist noch nicht die finale Freigabe für den produktiven Schulbetrieb.

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

## Release Candidate 1.0.0-rc.1

Für eine Neuinstallation ist ausschließlich das Release-Artefakt `FachDock-1.0.0-rc.1.zip` zu verwenden. Die daneben veröffentlichte Datei `FachDock-1.0.0-rc.1.zip.sha256` ermöglicht die unabhängige Prüfung des Downloads.

Der GitHub-Release wird als **Prerelease** markiert. Der integrierte FachDock-Updater bleibt auf stabile Releases beschränkt und bietet den RC daher nicht automatisch als Produktivupdate an. Das verhindert eine unbeabsichtigte Verteilung eines noch nicht final freigegebenen Standes.

Der Datenbank-Upgradepfad von `v0.9.0` auf den RC wird in der CI mit den Originalmigrationen des veröffentlichten Tags `v0.9.0` geprüft. Für die reale RC-Abnahme sollte eine bestehende Testinstallation vor manuellen Änderungen vollständig gesichert werden.

## Integrierte Updates

Für den Updatebetrieb muss zusätzlich das FachDock-Installationsverzeichnis für den Webserver-Prozess beschreibbar sein, weil Anwendungsdateien ersetzt werden. Lokale Konfiguration und `storage/` werden durch das Update nicht überschrieben.

Administratoren finden die Updateprüfung unter **Updates**. FachDock berücksichtigt dort ausschließlich stabile GitHub Releases. Vor der Installation wird das Release-ZIP anhand der veröffentlichten SHA-256-Prüfsumme geprüft. Vor dem Dateiaustausch wird ein Vollbackup erzeugt; während Dateiaustausch und Migrationen wird der Wartungsmodus aktiviert. Schlägt das Update fehl, versucht FachDock Dateien, Datenbank und persistente Daten aus dem Sicherheitsbackup wiederherzustellen.

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