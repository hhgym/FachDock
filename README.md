# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung soll den gesamten Lebenszyklus von Schließfächern abbilden – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen und Historie.

## Projektstatus

Aktuelle veröffentlichte Version: **0.6.1**

> FachDock befindet sich noch vor Version 1.0.0. Version 0.6.1 ist ein installierbarer Teststand für Web-Installer und Update-Routine sowie für die inzwischen durchgängigen Verwaltungs-, Reservierungs-, Elternportal-, BuT- und Stripe-Zahlungsabläufe. Der Patchrelease ergänzt die direkt editierbare öffentliche HTTPS-Basis-URL in der Stripe-Konfiguration und behebt horizontales Überlaufen auf kleinen Displays. Die Version ist weiterhin nicht für den produktiven Schulbetrieb vorgesehen.

## Bereits enthalten

- Web-Installer mit migrationsbasierter Datenbankeinrichtung
- lokale Anmeldung für Administratoren und Schließfachverwalter
- serverseitig widerrufbare Sitzungen und Passwortänderung
- zentrale responsive und rollenabhängige Navigation für Verwaltung und Elternportal
- operatives Dashboard mit Kennzahlen und Warnungen zu Buchungen, Reservierungen, BuT und Zahlungen
- Gebäude, Etagen, Bereiche, Korpustypen und Schrankgruppen
- barrierearme Fachpositionen und gemischte Korpustypen
- automatische Fachbezeichnungen wie `A-07-2` und `1OG-78-A-07-2`
- CSV-Schülerimport mit Vorschau, Validierung und Importprofilen
- permanente, nur gehasht gespeicherte Schüler-Zugangscodes mit einmaligem Export neu erzeugter Codes
- vollständige Schuljahresverwaltung
- Zuteilungsregeln mit Testfunktion und regelbasierten Schließfachempfehlungen
- transaktionale, konkurrenzsichere Reservierungen und Buchungsumwandlung
- administrative Buchungsauswahl
- zentrale Buchungsverwaltung mit Schuljahr-, Status- und Suchfiltern sowie Detailansichten
- zentrale Zahlungsverwaltung mit Stripe-Status, Fehlerdaten, Referenzen und Webhook-Verlauf
- Zuweisungshistorie und Zahlungsbezug in der Buchungsdetailansicht
- Elternkontakte mit historisierten Eltern-Kind-Verknüpfungen
- passwortloses Elternportal über einmalige, gehashte E-Mail-Magic-Links
- persistente E-Mail-Queue mit SMTP-Versand, Retry-Logik und versionierten E-Mail-Templates
- automatische und deduplizierte E-Mail-Benachrichtigungen für Buchungsbestätigung, Zahlungseingang, fehlgeschlagene oder abgelaufene Zahlungen sowie technische Prüfzustände
- verbindlicher BuT-Buchungsworkflow mit anschließender administrativer Prüfung
- E-Mail-Benachrichtigungen für BuT-Antrag, Genehmigung, Ablehnung und terminierte Zahlungserinnerung
- direkte Stripe-Zahlung einer bereits bestehenden `payment_due`-Buchung nach BuT-Ablehnung
- automatische Stornierung hinfälliger Zahlungserinnerungen nach erfolgreicher Zahlung oder Aktivierung
- Stripe Checkout für Elternbuchungen mit Test-/Live-Trennung
- öffentliche HTTPS-Basis-URL direkt in der Stripe-Konfiguration pflegbar; Webhook-Endpunkt wird daraus automatisch angezeigt
- Online-Zahlung im Elternportal nur bei vollständig gültiger Stripe- und HTTPS-Konfiguration
- kostenfreie verbindliche Buchung auch ohne eingerichtete Stripe-Zugangsdaten
- signierte und idempotente Stripe-Webhooks für erfolgreiche, fehlgeschlagene und abgelaufene Zahlungen
- sichere administrative Wiederherstellung bereits bezahlter `manual_review`-Vorgänge ohne erneute Zahlung
- einheitliches Application-Routing für Eltern-, Zahlungs-, Webhook- und Verwaltungsrouten
- MySQL-Integrationstests für Zahlungsworkflow, BuT-Folgezahlung, Benachrichtigungen und Wiederherstellung problematischer Zahlungen
- stabiler GitHub-Updatekanal mit SHA-256-Prüfung und automatischen Datenbankmigrationen

## Geplante Kernfunktionen

- interaktive Lagepläne je Etage
- Schüler- und Lehrkräftezugang über IServ/OIDC
- Verlängerung, Fachwechsel und weiterer Buchungslebenszyklus
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

Stabile Teststände werden als GitHub Releases mit Tags wie `v0.6.1` und später `v1.0.0` veröffentlicht. FachDock berücksichtigt beim integrierten Update ausschließlich stabile Releases.

## Installation

Die Erstinstallation erfolgt über den Web-Installer. Siehe `docs/INSTALLATION.md`.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OIDC-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.
