# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung bildet den gesamten Lebenszyklus von Schließfächern ab – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen, Datenschutz und Betrieb.

## Projektstatus

Aktuelle veröffentlichte Version: **1.0.0-rc.2**

> `1.0.0-rc.2` ist der zweite Release Candidate für FachDock 1.0. Er enthält die Korrekturen aus der manuellen Abnahme von `1.0.0-rc.1` und ist für die nächste produktionsnahe Testphase vorgesehen. Der Release Candidate ist noch nicht die finale Freigabe für den produktiven Schulbetrieb.

Release Candidates werden auf GitHub ausdrücklich als **Prerelease** veröffentlicht. Der integrierte FachDock-Updater bleibt auf stabile Releases beschränkt und bietet einen RC daher nicht automatisch als normales Produktivupdate an.

## Enthaltene Kernfunktionen

- Web-Installer mit migrationsbasierter Datenbankeinrichtung
- lokale Anmeldung für Administratoren und Schließfachverwalter
- IServ/OIDC für Schülerinnen und Schüler sowie Lehrkräfte
- passwortloses Elternportal über Magic Links
- zentrale responsive und rollenabhängige Navigation
- zentrale Konfiguration für Anwendung, Authentifizierung, OIDC, Buchung, Stripe und E-Mail
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
- stabiler GitHub-Updatekanal für freigegebene stabile Releases

## Release-Candidate-Abnahme

Für `1.0.0-rc.2` sollen insbesondere die in `rc.1` aufgefallenen realen Abläufe erneut geprüft werden:

1. Neuinstallation aus dem Release-ZIP auf einer leeren Datenbank.
2. Upgrade einer bestehenden Testinstallation mit gesichertem Datenbestand.
3. Anmeldung und Rechte als Administrator, Schließfachverwalter, Lehrkraft, Schüler und Elternkontakt.
4. Elternbuchung per Liste und Lageplan einschließlich Reservierung, Zahlung und BuT.
5. Lageplan-Zoom, Verschieben, Größenänderung und Rechteckplatzierung von Schrankgruppen.
6. CSV-Schülerimport einschließlich Vorschau und Fehleranzeige.
7. SMTP-Mailversand, Magic Links, Mail-Warteschlange und Stundenlimit/Reserve.
8. IServ/OIDC in der vorgesehenen Zielumgebung.
9. Defektmeldung und Notöffnung einschließlich mobiler Bedienung.
10. Vollbackup, Restore, Produktionsbereitschaftsprüfung und grundlegende Tastaturbedienung.

Die ausführliche Checkliste befindet sich in `docs/RELEASE_CANDIDATE.md`, der Anforderungsabgleich in `docs/REQUIREMENTS_AUDIT_1.0.md`.

## Noch nicht Bestandteil von 1.0

Die folgenden Punkte sind bewusst als spätere Erweiterungen vorgesehen und blockieren FachDock 1.0 nicht:

- optional kostenpflichtige Fachwechsel
- spätere REST-/WPDataAccess-API für automatisierte Stammdatenintegration
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

Stabile Releases verwenden Tags wie `v0.9.0` bzw. künftig `v1.0.0`. Release Candidates verwenden Tags wie `v1.0.0-rc.1` oder `v1.0.0-rc.2` und werden als GitHub-Prerelease markiert.

## Installation

Die Erstinstallation erfolgt über den Web-Installer. Siehe `docs/INSTALLATION.md`.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OIDC-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.
