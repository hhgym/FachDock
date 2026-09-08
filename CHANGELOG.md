# Changelog

Alle nennenswerten Änderungen an FachDock werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.1.1] - 2026-09-08

### Fixed
- Anmeldung mit nativen MySQL/MariaDB-PDO-Prepared-Statements korrigiert: Die Benutzer-/E-Mail-Suche verwendet nun zwei eindeutige benannte Parameter und löst dadurch nicht mehr `SQLSTATE[HY093]: Invalid parameter number` aus.
- Regressionstest ergänzt, der doppelte benannte PDO-Platzhalter in der Login-Abfrage verhindert.

## [0.1.0] - 2026-09-08

### Added
- PHP-8.3-/Composer-Grundgerüst mit CI, PHPUnit, PHPStan und PHP-CS-Fixer.
- Web-Installer für Datenbank, Schuleinstellungen und erstes Administratorkonto.
- Versionsbasierter Migrationsrunner und CLI-Migrationsbefehl.
- Lokale Anmeldung für Administratoren und Schließfachverwalter mit serverseitig widerrufbaren Sitzungen.
- Konfigurierbare Sitzungs- und Login-Sicherheitsgrenzen sowie Passwortänderung.
- Standortmodell `Gebäude → Etage → Bereich → Schrankgruppe → Korpus → Schließfach`.
- Korpustypen mit konfigurierbaren barrierearmen Positionen.
- Automatische Schrankgruppen- und Fachbezeichnungen, z. B. `A-07-2` und `1OG-78-A-07-2`.
- Transaktionale Erzeugung und Umstrukturierung bislang ungenutzter Schrankgruppen.
- Erste Administrationsoberfläche für Standorte und Schrankgruppen.
- Administrator-gesteuerte Updateprüfung für ausschließlich stabile GitHub Releases.
- SHA-256-Prüfung, sichere ZIP-Extraktion, Wartungsmodus, Dateisicherung und automatische Migrationen beim Update.
- GitHub-Release-Workflow für Produktions-ZIP und SHA-256-Prüfsumme.
