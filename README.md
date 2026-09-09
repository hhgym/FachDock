# FachDock

**Open-Source-Schließfachverwaltung für Schulen**

FachDock ist eine webbasierte Verwaltungs- und Buchungslösung für schulische Schließfächer. Die Anwendung soll den gesamten Lebenszyklus von Schließfächern abbilden – vom Standort und Lageplan über Schülerimport, Buchung, Zahlung und Verlängerung bis zu Defektmeldungen, Notöffnungen und Historie.

## Projektstatus

Aktuelle veröffentlichte Version: **0.9.0**

> FachDock befindet sich noch vor Version 1.0.0. Version 0.9.0 ist ein installierbarer Teststand für Web-Installer und Update-Routine sowie für die inzwischen durchgängigen Verwaltungs-, Reservierungs-, Elternportal-, BuT-, Stripe-Zahlungs-, Buchungslebenszyklus- und Schließfachbetriebs-Abläufe. Neu sind insbesondere interaktive Lagepläne, der automatisierte Schuljahreswechsel mit Erinnerungen und täglichem Systemjob sowie Datenschutz-, Export-, Produktionsbereitschafts- und Security-Härtungen. Die Version ist weiterhin nicht für den produktiven Schulbetrieb vorgesehen.

## Bereits enthalten

- Web-Installer mit migrationsbasierter Datenbankeinrichtung
- lokale Anmeldung für Administratoren und Schließfachverwalter
- serverseitig widerrufbare Sitzungen und Passwortänderung
- zentrale responsive und rollenabhängige Navigation für Verwaltung und Elternportal
- zentrale Konfigurationsverwaltung für allgemeine Einstellungen, Authentifizierung, Buchung, Stripe und E-Mail
- operatives Dashboard mit Kennzahlen und Warnungen zu Buchungen, Reservierungen, BuT und Zahlungen
- zentraler Systemstatus mit Datenbank-, SMTP-, Stripe-, Dateisystem-, Mail-Worker- und Schuljahresjob-Prüfung
- Produktionsbereitschaftsprüfung für zentrale technische Voraussetzungen des späteren Echtbetriebs
- persistente Worker-Heartbeats mit letztem Start, Erfolg und Fehlerstatus
- zentrale Security-Header mit CSP, Frame-Schutz, `nosniff`, Referrer-Policy, Permissions-Policy und HSTS bei HTTPS
- Gebäude, Etagen, Bereiche, Korpustypen und Schrankgruppen
- barrierearme Fachpositionen und gemischte Korpustypen
- automatische Fachbezeichnungen wie `A-07-2` und `1OG-78-A-07-2`
- mehrere interaktive Bild-Lagepläne je Etage mit Zoom und administrativer Positionierung von Schrankgruppen
- Live-Anzeige von Schrankgruppen, Fächern und Verfügbarkeit im Lageplan nach Schuljahr
- lesender Lageplanzugriff im Elternportal
- technische Schließfachzustände `Betriebsbereit`, `Gesperrt`, `Defekt`, `Wartung` und `Außer Betrieb`
- operative Vorgangsverwaltung für Defekte, Schloss-/Türprobleme, Beschädigungen, vergessene Codes und Notöffnungen
- dokumentierte Notöffnungen sowie technische Schließfach- und Vorgangshistorie
- automatische Sperre defekter Fächer für neue Buchungen ohne Beendigung laufender Buchungen
- Eltern-Selbstservice zur Meldung von Schließfachproblemen mit Statusanzeige und E-Mail-Benachrichtigung
- Schüler-Selbstservice als Fallback über Matrikelnummer und bestehenden FachDock-Zugangscode
- CSV-Schülerimport mit Vorschau, Validierung und Importprofilen
- permanente, nur gehasht gespeicherte Schüler-Zugangscodes mit einmaligem Export neu erzeugter Codes
- vollständige Schuljahresverwaltung
- automatisierter Schuljahreswechsel zwischen unmittelbar aufeinanderfolgenden Schuljahren
- deduplizierte Verlängerungserinnerungen zum 01.06., 01.07. und 20.07.
- idempotenter Roll-over ab 01.08. mit Freigabe nicht verlängerter Fächer und protokolliertem Abschluss alter Buchungen
- täglicher CLI-Systemjob `php bin/fachdock school-year:tick` für Erinnerungen und fällige Schuljahreswechsel
- Zuteilungsregeln mit Testfunktion und regelbasierten Schließfachempfehlungen
- transaktionale, konkurrenzsichere Reservierungen und Buchungsumwandlung
- administrative Buchungsauswahl
- zentrale Buchungsverwaltung mit Schuljahr-, Status- und Suchfiltern sowie Detailansichten
- zentrale Zahlungsverwaltung mit Stripe-Status, Fehlerdaten, Referenzen und Webhook-Verlauf
- Zuweisungs- und Buchungslebenszyklushistorie in der Buchungsdetailansicht
- administrative Verlängerung aktiver Buchungen in spätere Schuljahre mit erneuter Regel- und Verfügbarkeitsprüfung
- regelkonformer Schließfachwechsel mit atomarem Austausch der Belegung und historischer Dokumentation
- Beenden und Stornieren von Buchungen mit Freigabe von Buchungsslot und Schließfach
- E-Mail-Benachrichtigungen für Schließfachwechsel, Verlängerung, Beendigung und Stornierung
- Elternkontakte mit historisierten Eltern-Kind-Verknüpfungen
- passwortloses Elternportal über einmalige, gehashte E-Mail-Magic-Links
- persistente E-Mail-Queue mit SMTP-Versand, Retry-Logik und versionierten E-Mail-Templates
- automatische und deduplizierte E-Mail-Benachrichtigungen für Buchungsbestätigung, Zahlungseingang, fehlgeschlagene oder abgelaufene Zahlungen sowie technische Prüfzustände
- verbindlicher BuT-Buchungsworkflow mit anschließender administrativer Prüfung
- E-Mail-Benachrichtigungen für BuT-Antrag, Genehmigung, Ablehnung und terminierte Zahlungserinnerung
- direkte Stripe-Zahlung einer bereits bestehenden `payment_due`-Buchung nach BuT-Ablehnung
- automatische Stornierung hinfälliger Zahlungserinnerungen nach erfolgreicher Zahlung, Aktivierung, Beendigung oder Stornierung
- Stripe Checkout für Elternbuchungen mit Test-/Live-Trennung
- öffentliche HTTPS-Basis-URL direkt in der Stripe-Konfiguration pflegbar; Webhook-Endpunkt wird daraus automatisch angezeigt
- Online-Zahlung im Elternportal nur bei vollständig gültiger Stripe- und HTTPS-Konfiguration
- kostenfreie verbindliche Buchung auch ohne eingerichtete Stripe-Zugangsdaten
- signierte und idempotente Stripe-Webhooks für erfolgreiche, fehlgeschlagene und abgelaufene Zahlungen
- sichere administrative Wiederherstellung bereits bezahlter `manual_review`-Vorgänge ohne erneute Zahlung
- Sperre von Lebenszyklusänderungen während laufender oder technisch/manuell zu prüfender Zahlungsvorgänge
- konfigurierbare Aufbewahrungsfristen für personenbezogene Daten und E-Mail-Inhalte
- kontrollierte Datenschutz-Anonymisierung mit Vorschau, Sicherheitsbestätigung und protokollierten Läufen
- Pseudonymisierung historischer Schüler- und Elterndaten unter Erhalt notwendiger Geschäftsdatensätze
- Bereinigung alter Mail-Inhalte und Audit-Metadaten
- administrative CSV-Exporte für Buchungen, Zahlungen, Schließfachmeldungen und Auditdaten
- einheitliches Application-Routing für Eltern-, Zahlungs-, Webhook- und Verwaltungsrouten
- MySQL-Integrationstests für Zahlungsworkflow, BuT-Folgezahlung, Benachrichtigungen, Buchungslebenszyklus, Schließfachsupport, Lagepläne, Schuljahreswechsel, Datenschutz, Worker-Monitoring und Wiederherstellung problematischer Zahlungen
- stabiler GitHub-Updatekanal mit SHA-256-Prüfung und automatischen Datenbankmigrationen

## Geplante Kernfunktionen

- Schüler- und Lehrkräftezugang über IServ/OIDC
- Web-Push und weitere Systemjobs
- PDF-Exporte und Dokumentenarchiv
- weitere Datenschutz-, Audit- und Sicherheitsprüfungen vor Version 1.0.0
- weitere Produktionshärtung, Betriebsdokumentation und Backup-/Restore-Absicherung vor Version 1.0.0

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

Stabile Teststände werden als GitHub Releases mit Tags wie `v0.9.0` und später `v1.0.0` veröffentlicht. FachDock berücksichtigt beim integrierten Update ausschließlich stabile Releases.

## Installation

Die Erstinstallation erfolgt über den Web-Installer. Siehe `docs/INSTALLATION.md`.

## Sicherheit

Geheime Konfigurationswerte wie Stripe-, SMTP- oder OIDC-Secrets werden nicht in das Repository eingecheckt. Lokale Secrets werden außerhalb des Webroots in einer lokalen Konfigurationsdatei verwaltet.

## Lizenz

FachDock wird unter der **GNU Affero General Public License v3.0 (AGPL-3.0-only)** veröffentlicht.
