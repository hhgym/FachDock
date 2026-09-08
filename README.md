# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung soll den gesamten Lebenszyklus von Schließfächern abbilden – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen und Historie.

## Projektstatus

FachDock befindet sich in der frühen Entwicklung.

Aktueller Entwicklungsstand: **0.1.0-dev**

## Geplante Kernfunktionen

- mehrgebäudefähige Standortverwaltung
- interaktive Lagepläne je Etage
- Schrankgruppen, Korpusse und automatisch erzeugte Schließfächer
- regelbasierte Schließfachvorschläge und konkrete Fachauswahl
- Elternportal mit E-Mail-Magic-Link
- Schülerzugang über IServ/OIDC
- CSV-Schülerimport mit Importprofilen und Vorschau
- Buchung, Verlängerung und Fachwechsel
- Stripe-Zahlungen und vorbereitete automatische Verlängerung
- BuT-Befreiungsworkflow
- Defektmeldungen und Notöffnungen
- E-Mail-Queue, Web-Push und Systemjobs
- PDF-/CSV-Exporte und Dokumentenarchiv
- Audit-Log, Aufbewahrungs- und Anonymisierungsregeln
- Web-Installer und migrationsbasierte Updates
- Updateprüfung über GitHub Releases

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

Produktive Versionen werden als GitHub Releases mit Tags wie `v1.0.0` veröffentlicht.

## Installation

Die Erstinstallation erfolgt über einen Web-Installer. Eine Installationsanleitung folgt mit der ersten lauffähigen Version.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OIDC-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0)** veröffentlicht.
