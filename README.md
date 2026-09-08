# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung soll den gesamten Lebenszyklus von Schließfächern abbilden – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen und Historie.

## Projektstatus

Aktuelle veröffentlichte Version: **0.4.0**

> FachDock befindet sich noch vor Version 1.0.0. Version 0.4.0 ist ein installierbarer Teststand für Web-Installer und Update-Routine sowie für die inzwischen weitgehend durchgängigen Verwaltungs-, Reservierungs-, Elternportal-, BuT- und Stripe-Zahlungsabläufe. Sie ist weiterhin nicht für den produktiven Schulbetrieb vorgesehen.

## Bereits enthalten

- Web-Installer mit migrationsbasierter Datenbankeinrichtung
- lokale Anmeldung für Administratoren und Schließfachverwalter
- serverseitig widerrufbare Sitzungen und Passwortänderung
- zentrale responsive und rollenabhängige Navigation für Verwaltung und Elternportal
- Gebäude, Etagen, Bereiche, Korpustypen und Schrankgruppen
- barrierearme Fachpositionen und gemischte Korpustypen
- automatische Fachbezeichnungen wie `A-07-2` und `1OG-78-A-07-2`
- CSV-Schülerimport mit Vorschau, Validierung und Importprofilen
- permanente, nur gehasht gespeicherte Schüler-Zugangscodes mit einmaligem Export neu erzeugter Codes
- vollständige Schuljahresverwaltung
- Zuteilungsregeln mit Testfunktion und regelbasierten Schließfachempfehlungen
- transaktionale, konkurrenzsichere Reservierungen und Buchungsumwandlung
- administrative Buchungsauswahl
- Elternkontakte mit historisierten Eltern-Kind-Verknüpfungen
- passwortloses Elternportal über einmalige, gehashte E-Mail-Magic-Links
- persistente E-Mail-Queue mit SMTP-Versand, Retry-Logik und versionierten E-Mail-Templates
- verbindlicher BuT-Buchungsworkflow mit anschließender administrativer Prüfung
- Stripe Checkout für Elternbuchungen mit Test-/Live-Trennung
- signierte und idempotente Stripe-Webhooks für erfolgreiche, fehlgeschlagene und abgelaufene Zahlungen
- MySQL-Integrationstests für den Zahlungsworkflow
- stabiler GitHub-Updatekanal mit SHA-256-Prüfung und automatischen Datenbankmigrationen

## Geplante Kernfunktionen

- interaktive Lagepläne je Etage
- Schüler- und Lehrkräftezugang über IServ/OIDC
- Verlängerung und Fachwechsel
- Defektmeldungen und Notöffnungen
- Web-Push und weitere Systemjobs
- PDF-/CSV-Exporte und Dokumentenarchiv
- erweiterte Audit-, Aufbewahrungs- und Anonymisierungsfunktionen
- weitere Konsolidierung der Administrations- und Konfigurationsoberflächen vor Version 1.0.0

## Technische Basis

- PHP 8.3+
- MariaDB 10.6+ oder MySQL 8.0+
- InnoDB und utf8mb4
- Composer
- serverseitig gerenderte PHP-Templates
- Vanilla JavaScript bzw. kleine spezialisierte Bibliotheken
- Apache 2.4+ und nginx

## Entwicklungsmodell

FachDock verwendet Git Flow:

- `main` – veröffentlichte bzw. produktionsreife Stände
- `develop` – Integration für die nächste Version
- `feature/*` – fachlich zusammenhängende Entwicklung
- `release/*` – Release-Stabilisierung
- `hotfix/*` – dringende Korrekturen veröffentlichter Versionen

Stabile Teststände werden als GitHub Releases mit Tags wie `v0.4.0` und später `v1.0.0` veröffentlicht. FachDock berücksichtigt beim integrierten Update ausschließlich stabile Releases.

## Installation

Die Erstinstallation erfolgt über den Web-Installer. Siehe `docs/INSTALLATION.md`.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OIDC-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.
