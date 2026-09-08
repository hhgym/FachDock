# Changelog

Alle nennenswerten Änderungen an FachDock werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.2.0] - 2026-09-08

### Added
- Schülerstammdaten mit eindeutigem Matrikelnummernbezug, Klassenstufe, optionaler E-Mail-Adresse und Aktivstatus.
- Administratorgeführter CSV-Import mit Vorschau, Validierung, wiederverwendbaren Importprofilen, vollständigem/partiellem Import und Importhistorie.
- Permanente Schüler-Zugangscodes mit ausschließlich gehashter Speicherung und einmaligem CSV-Export neu erzeugter Klartextcodes.
- Erweiterte Standortadministration mit Auswahlfeldern statt Datenbank-IDs, Bearbeitung von Gebäuden, Etagen, Bereichen und Korpustypen sowie Aktiv/Inaktiv-Lebenszyklus.
- Vollständiger Lebenszyklus für Schrankgruppen einschließlich Löschung nur bei historisch ungenutzten Gruppen und dauerhafter Struktursperre nach erster Nutzung.
- Zentraler Audit-Logger für administrative Änderungen an Standortdaten.
- Schuljahresmodell vom 1. August bis 31. Juli mit konfigurierbarem Öffnungsdatum für Neubuchungen.
- Zuteilungsregeln mit verbindlichen Erlaubnissen, Ausschlüssen und gewichteten Empfehlungen.
- Historische Buchungs-, Belegungs- und Zuweisungsdaten mit separaten Tabellen zur Eindeutigkeit aktuell aktiver Buchungen.
- Transaktionale Schließfachreservierungen mit 15 Minuten Standarddauer und 30 Minuten Zahlungs-Gnadenfrist.
- Konkurrenzsichere Reservierungslogik für genau eine aktive Reservierung je Schüler/Schuljahr und Schließfach/Schuljahr.
- Transaktionale Umwandlung einer Reservierung in Buchung, Belegung und Zuweisungshistorie inklusive Schüler-, Regel- und Standort-Snapshots.
- Anteilsberechnung des Jahresbeitrags nach verbleibenden Monaten inklusive Buchungsmonat.
- Unit-Tests für Schuljahresgrenzen, Gebührenanteile, Zuteilungsregeltypen und persistierte Schließfachzustände.

### Changed
- Die Standortverwaltung zeigt technische Datenbankfehler nicht mehr direkt an, sondern verwendet benutzerfreundliche Fehler-IDs.
- Historische Buchungen blockieren keine spätere erneute Buchung im selben Schuljahr; nur die aktive Buchung wird eindeutig erzwungen.
- Ablaufentscheidungen für Reservierungen verwenden konsistent die Datenbankzeit.

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
