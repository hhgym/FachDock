# Changelog

Alle nennenswerten Änderungen an FachDock werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.4.0] - 2026-09-08

### Added
- Verbindlicher BuT-Buchungsweg: Eine aktive Reservierung kann als Buchung mit zunächst zu prüfender Gebührenbefreiung übernommen werden.
- Administrationsqueue für BuT-Prüfungen mit Freigabe, Ablehnung, Prüfvermerk und konfigurierbarer Zahlungsfrist nach Ablehnung.
- Stripe Checkout für reguläre Elternbuchungen mit Customer-Zuordnung, anteiliger Gebührenberechnung und serverseitigem Zahlungsstatus.
- Signierte und idempotente Stripe-Webhooks für erfolgreiche, asynchron erfolgreiche, fehlgeschlagene und abgelaufene Zahlungen.
- Zahlungsstatusseite im Elternportal sowie persistierte Zahlungsversuche und Stripe-Webhook-Ereignisse.
- Zentrales responsives FachDock-Menü für Verwaltung und Elternportal mit gruppierter Navigation, aktivem Menüpunkt und Konto-Menü.
- Rollenabhängige Menüs für Administratoren und Schließfachverwaltung.
- MySQL-8.4-Integrationstests für den Stripe-Checkout- und Webhook-Workflow einschließlich der einmaligen Reservierung-zu-Buchung-Konvertierung.
- Reale Stripe-Signaturprüfung in den automatisierten Tests.

### Changed
- Stripe kann explizit im Test- oder Live-Modus betrieben werden; Test- und Live-Schlüssel werden gegeneinander validiert, um versehentliche Live-Zahlungen in Testinstallationen zu verhindern.
- Die bestehenden Einzel-Header authentifizierter Seiten werden zentral durch die gemeinsame Navigation ersetzt.
- Der CI-Lauf verwendet für datenbankabhängige Zahlungstests MySQL 8.4.

### Fixed
- Die Stripe-Zahlungsabfrage verwendet keinen mit MySQL 8.4 kollidierenden Alias für `CURRENT_DATE` mehr.

## [0.3.0] - 2026-09-08

### Added
- Administrationsoberfläche für Zuteilungsregeln mit Gültigkeitszeiträumen, Klassenstufen, harten Erlaubnis-/Ausschlussregeln und gewichteten Empfehlungen.
- Testfunktion für Zuteilungsregeln und regelkonforme Schließfachempfehlungen mit konfigurierbarer Anzahl von Vorschlägen.
- Administrativer Buchungsauswahl-Workflow mit echter transaktionaler Reservierung und Austausch einer noch nicht bezahlten Reservierung.
- Vollständige Schuljahresverwaltung inklusive automatischer Anlage von aktuellem und folgendem Schuljahr, Statuspflege, Öffnungsdatum für Neubuchungen und zeitweiser Korrekturöffnung geschlossener Jahre.
- Elternkontakte mit historisierten n:m-Verknüpfungen zu Schülerinnen und Schülern.
- Einmalige, gehashte Magic Links zur E-Mail-Verifikation und passwortlosen Elternanmeldung.
- Öffentliches Elternportal mit widerrufbaren 24-Stunden-Sitzungen und ausschließlich den aktiv verknüpften eigenen Kindern.
- Persistente E-Mail-Queue mit SMTP-Versand über PHPMailer, Prioritäten, Ablaufzeit, Deduplizierung, Retry-Schedule und stündlichem Versandlimit.
- Versionierte E-Mail-Templates mit getrennten HTML-/Textfassungen und streng validierten Platzhaltern.
- Administrationsoberfläche für E-Mail-Templates und Versandqueue mit manuellem Retry und Abbruch.
- CLI-Befehl `bin/fachdock mail:work` für den regelmäßigen Versand per Cronjob.
- Versandhistorie ohne dauerhafte Speicherung von Mail-Body oder geheimen Magic-Link-Platzhaltern.

### Changed
- Magic-Link-URLs werden ausschließlich aus der konfigurierten kanonischen HTTPS-Basis-URL erzeugt und nicht aus dem eingehenden Host-Header abgeleitet.
- Öffentliche Eltern-Login-Anfragen liefern unabhängig von Existenz, Verifikationsstatus oder Rate-Limit eines Kontakts dieselbe Antwort und verhindern damit Account Enumeration.
- Reservierungen berücksichtigen die prognostizierte Klassenstufe des Zielschuljahres und die dafür gültigen Zuteilungsregeln.
- Der Anwendungs-Bootstrap bindet Schuljahres-, Eltern-, Mail-, Regel-, Empfehlungs- und Buchungsauswahlmodule zentral ein.

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
