# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung soll den gesamten Lebenszyklus von Schließfächern abbilden – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen und Historie.

## Projektstatus

Aktuelle Version: **0.1.1**

> FachDock befindet sich noch vor Version 1.0.0. Version 0.1.1 ist ein früher, installierbarer Teststand für die technische Basis, den Web-Installer, die lokale Administration, die Standort-/Schließfachstruktur und die Update-Routine. Sie ist noch nicht für den produktiven Schulbetrieb vorgesehen.

## Bereits enthalten

- Web-Installer mit migrationsbasierter Datenbankeinrichtung
- lokale Anmeldung für Administratoren und Schließfachverwalter
- serverseitig widerrufbare Sitzungen und Passwortänderung
- Gebäude, Etagen und Bereiche
- Korpustypen und barrierearme Fachpositionen
- Schrankgruppen mit gemischten Korpustypen
- automatische Fachbezeichnungen wie `A-07-2` und `1OG-78-A-07-2`
- erste Verwaltungsoberfläche für die physische Schließfachstruktur
- stabiler GitHub-Updatekanal mit SHA-256-Prüfung und Datenbankmigrationen

## Geplante Kernfunktionen

- interaktive Lagepläne je Etage
- Schülerimport und dauerhafte Zugangscodes
- regelbasierte Schließfachvorschläge und konkrete Fachauswahl
- Elternportal mit E-Mail-Magic-Link
- Schüler- und Lehrkräftezugang über IServ/OIDC
- Buchung, Verlängerung und Fachwechsel
- Stripe-Zahlungen und BuT-Befreiungsworkflow
- Defektmeldungen und Notöffnungen
- E-Mail-Queue, Web-Push und Systemjobs
- PDF-/CSV-Exporte und Dokumentenarchiv
- Audit-, Aufbewahrungs- und Anonymisierungsfunktionen

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

Stabile Versionen werden als GitHub Releases mit Tags wie `v0.1.1` und später `v1.0.0` veröffentlicht. FachDock berücksichtigt beim integrierten Update ausschließlich stabile Releases.

## Installation

Die Erstinstallation erfolgt über den Web-Installer. Siehe `docs/INSTALLATION.md`.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OIDC-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.
