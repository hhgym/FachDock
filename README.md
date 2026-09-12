# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung bildet den gesamten Lebenszyklus von Schließfächern ab – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen, Datenschutz und Betrieb.

## Projektstatus

Aktuelle veröffentlichte Version: **1.0.0-rc.3**

> `1.0.0-rc.3` ist der dritte Release Candidate für FachDock 1.0. Er erweitert `1.0.0-rc.2` insbesondere um den realen OpenID-Connect-Testlogin mit Claim-Anzeige, konfigurierbare Schülerzuordnung, auswählbare Updatekanäle sowie die getrennte Verwaltung und den automatisierten Lebenszyklus von Schüler- und Elternkonten. Der Release Candidate ist noch nicht die finale Freigabe für den produktiven Schulbetrieb.

Release Candidates werden auf GitHub ausdrücklich als **Prerelease** veröffentlicht. Der integrierte FachDock-Updater verwendet standardmäßig ausschließlich stabile Releases. Ein RC-Kanal kann gezielt freigeschaltet werden; ein zusätzlicher Develop-Kanal kann für rolling Test-Builds aktiviert werden.

## Enthaltene Kernfunktionen

- Web-Installer mit migrationsbasierter Datenbankeinrichtung
- lokale Anmeldung für Administratoren und Schließfachverwalter einschließlich Benutzer-Lifecycle
- OpenID Connect für Schülerinnen und Schüler sowie Lehrkräfte, mit echtem Admin-Testlogin und Anzeige der übertragenen UserInfo-Claims
- konfigurierbare Schülerzuordnung über Rolle, Claim und Zielfeld, unter anderem `untis_username` → Matrikelnummer
- passwortloses Elternportal über Magic Links
- zentrale responsive und rollenabhängige Navigation
- zentrale Konfiguration für Anwendung, Authentifizierung, OpenID Connect, Buchung, Stripe und E-Mail
- getrennte Übersichten für Schülerstammdaten und Benutzerkonten
- konfigurierbarer Schüler-Lifecycle mit Nachlauf, Kontosperre, Rückkehrbehandlung und Anonymisierung
- konfigurierbarer Eltern-Lifecycle nach Wegfall des letzten aktiven Kindes
- Gebäude, Etagen, Bereiche, Schrankgruppen, Korpustypen und automatisch erzeugte Schließfächer
- automatische Fachbezeichnungen wie `A-07-2`
- mehrere Bild-Lagepläne je Etage mit Zoom, Verschieben, Größenanpassung und direkter Rechteckplatzierung vorhandener Schrankgruppen
- interaktive Schließfachauswahl im Elternportal mit Live-Verfügbarkeit
- Zuteilungsregeln für Klassenstufen, feste Bereiche und Empfehlungen
- Schuljahresverwaltung mit Laufzeit 01.08.–31.07.
- Verlängerung, Fachwechsel und regelkonforme Ersatzfachauswahl
- automatisierte Verlängerungserinnerungen am 01.06., 01.07. und 20.07.
- automatisierter Schuljahreswechsel ab 01.08.
- 15-Minuten-Reservierungen und transaktionale Buchungsumwandlung
- BuT-Prüfworkflow mit anschließender Zahlungslogik
- Stripe Checkout mit Test-/Live-Trennung und signierten, idempotenten Webhooks
- zentrale Buchungs- und Zahlungsverwaltung mit Suche, Filtern und serverseitiger Pagination
- Defektmeldungen, Notöffnungen und technische Schließfachzustände mit Historie
- Schüler- und Eltern-Self-Service für Schließfachprobleme
- CSV-Schülerimport mit robuster Vorschau und automatischer Erkennung üblicher Trennzeichen sowie administrative CSV-Exporte
- E-Mail-Queue mit SMTP, Retry-Logik, Sofortversand zeitkritischer Magic-/Bestätigungslinks und geschützter Versandreserve
- zentrale Audit-Protokollierung
- Datenschutz-Aufbewahrung und automatisierte Anonymisierung
- Security-Header einschließlich CSP, Frame-Schutz und HSTS bei HTTPS
- Produktionsbereitschaftsprüfung und Systemstatus
- Vollbackup und vollständiger Restore mit SHA-256-Prüfung
- automatische Sicherheitsbackups vor Restore und Self-Update
- Systemjobs für Mailversand, Schuljahreswechsel, Datenschutz und Backups
- Updatekanäle Stable, RC und optional Develop; Stable bleibt die Voreinstellung

## Release-Candidate-Abnahme

Für `1.0.0-rc.3` sollen neben den bisherigen Kernabläufen insbesondere die seit `rc.2` hinzugekommenen Funktionen geprüft werden:

1. Neuinstallation aus dem Release-ZIP auf einer leeren Datenbank und Upgrade einer bestehenden Testinstallation.
2. Anmeldung und Rechte als Administrator, Schließfachverwalter, Lehrkraft, Schüler und Elternkontakt.
3. OpenID-Connect-Discovery und echter Testlogin mit Kontrolle der übertragenen Claims.
4. Automatische Schülerzuordnung über konfigurierte Rolle und Claim, insbesondere optional `untis_username` → Matrikelnummer.
5. Schüler-Lifecycle: 30-Tage-Nachlauf, automatische Sperre, Rückkehr und Anonymisierung nach einem Jahr.
6. Eltern-Lifecycle: Fristbeginn erst nach Wegfall des letzten aktiven Kindes und Standardfrist von drei Jahren.
7. Manuelle Deaktivierung/Reaktivierung sowie sofortige Anonymisierung nur nach exakter Texteingabe-Bestätigung.
8. Getrennte Ansichten für Schülerdaten und Benutzerkonten einschließlich Verweis vom Schüler zum vorhandenen Konto.
9. Stable-, RC- und Develop-Updatekanal einschließlich korrekter Upgrade-Erkennung ohne Downgrade.
10. Elternbuchung per Liste und Lageplan einschließlich Reservierung, Zahlung und BuT sowie die übrigen Betriebs- und Backupabläufe.

Die ausführliche Checkliste befindet sich in `docs/RELEASE_CANDIDATE.md`, der Anforderungsabgleich in `docs/REQUIREMENTS_AUDIT_1.0.md`.

## Noch nicht Bestandteil von 1.0

Die folgenden Punkte sind bewusst als spätere Erweiterungen vorgesehen und blockieren FachDock 1.0 nicht:

- optional kostenpflichtige Fachwechsel
- spätere REST-/WPDataAccess-API für automatisierte Stammdatenintegration
- optionaler OpenID-Connect-Zugang für Eltern; das Elternportal verwendet weiterhin Magic Links
- weitergehende Dokumenten- und PDF-Funktionen

## Technische Basis

- PHP 8.3+
- MariaDB 10.6+ oder MySQL 8.0+
- InnoDB und utf8mb4
- Composer
- serverseitig gerenderte PHP-Templates
- Vanilla JavaScript
- Apache 2.4+ oder nginx

## Entwicklungsmodell

FachDock verwendet Git Flow:

- `main` – veröffentlichte Stände
- `develop` – Integration für die nächste Version
- `feature/*` – fachlich zusammenhängende Entwicklung
- `release/*` – Release-Stabilisierung
- `hotfix/*` – dringende Korrekturen veröffentlichter Versionen

Stabile Releases verwenden Tags wie `v0.9.0` bzw. künftig `v1.0.0`. Release Candidates verwenden Tags wie `v1.0.0-rc.1`, `v1.0.0-rc.2` oder `v1.0.0-rc.3` und werden als GitHub-Prerelease markiert.

## Installation

Die Erstinstallation erfolgt über den Web-Installer. Siehe `docs/INSTALLATION.md`.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OpenID-Connect-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.
