# FachDock 1.0 – Anforderungsabgleich

Stand: Release-Candidate-Phase D

Diese Matrix gleicht die ursprünglich vereinbarten Anforderungen mit dem Entwicklungsstand für FachDock 1.0 ab. `ERFÜLLT` bedeutet, dass die Funktion für 1.0 implementiert und durch bestehende automatisierte Tests, Release-Candidate-Tests oder die technische Struktur abgesichert ist. `NACH 1.0` kennzeichnet Punkte, die bereits in der ursprünglichen Planung ausdrücklich als spätere Erweiterung vorgesehen waren und deshalb kein 1.0-Blocker sind.

| Bereich | Anforderung | Status | Umsetzung / Nachweis |
| --- | --- | --- | --- |
| Struktur | Mehrere Gebäude verwalten | ERFÜLLT | Hierarchie Gebäude → Etage/Bereich → Schrankgruppe → Korpus → Fach |
| Struktur | 3er-Korpus Standard, 4er-Korpus möglich, Mischung in Gruppen | ERFÜLLT | konfigurierbare Korpustypen und Korpusfolge je Schrankgruppe |
| Struktur | Fächer werden aus Korpustyp und Position automatisch erzeugt | ERFÜLLT | Standort-/Korpusverwaltung |
| Struktur | Reihenfolge vor dem Speichern gestaltbar, danach stabile Positionen | ERFÜLLT | Korpusfolge und persistente Positionsnummern |
| Struktur | Fachstatus buchbar / reserviert / nicht buchbar sowie technischer Status | ERFÜLLT | Buchbarkeit, Reservierungs-Slots und `operating_status` |
| Nummerierung | Kurzname `[Gruppe]-[Korpus zweistellig]-[Position]` | ERFÜLLT | zentrale `LockerNaming`-Logik |
| Nummerierung | Flexible Gebäude-/Etagen-/Bereichscodes und Langname | ERFÜLLT | Standortkatalog und zentrale Langnamenerzeugung |
| Lagepläne | Mehrere Pläne je Etage | ERFÜLLT | FloorPlan-Modul |
| Lagepläne | Zoom und positionierte Schrankgruppen | ERFÜLLT | interaktive Lageplanansicht |
| Lagepläne | Klick auf Gruppe zeigt Korpusse/Fächer und Verfügbarkeit | ERFÜLLT | Admin- und Elternansicht mit Live-Buchungszuständen |
| Lagepläne | Nicht positionierte Gruppen bleiben erreichbar | ERFÜLLT | Seitenleiste der Buchungsansicht |
| Lagepläne | Tastaturbedienung | ERFÜLLT | native Buttons plus Pfeil/Home/End-Navigation der Marker und sichtbarer Fokus |
| Schuljahr | Buchungsjahr 01.08.–31.07. | ERFÜLLT | Schuljahresmodell und Übergangslogik |
| Schuljahr | Verlängerung jederzeit möglich | ERFÜLLT | Eltern- und Admin-Self-Service |
| Schuljahr | Nicht verlängerte Fächer werden zum 01.08. frei | ERFÜLLT | idempotenter `school-year:tick` und Roll-over |
| Schuljahr | Erinnerungen 01.06., 01.07., 20.07. | ERFÜLLT | Schuljahresübergang und Mail-Queue |
| Zielgruppen | Alle aktiven Schüler buchungsberechtigt | ERFÜLLT | Schüler-/Elternzuordnung und Buchungslogik |
| Regeln | 5./6. feste Bereiche als harte Regeln | ERFÜLLT | regelbasierte `hard_allow`/`hard_deny`-Zuteilung |
| Regeln | Ältere Schüler bevorzugt auf höheren Etagen | ERFÜLLT | gewichtete weiche Zuteilungsregeln/Empfehlungen |
| Regeln | Klasse 6 → 7 muss aus 5/6-Bereich wechseln | ERFÜLLT | explizite Erneuerungsregel mit Eltern- und Admin-Integrationstests |
| Gebühren | Jahresgebühr pro Schuljahr | ERFÜLLT | Gebühren-Snapshot je Buchung |
| Gebühren | Anteilige Gebühr bei späterem Einstieg | ERFÜLLT | `FeeCalculator` und `proration_months` |
| BuT | BuT-Kinder nach Prüfung kostenfrei | ERFÜLLT | Prüfworkflow, Freigabe/Ablehnung und Folgezahllogik |
| Wechsel | Fachwechsel jederzeit in freies regelkonformes Fach | ERFÜLLT | Eltern- und Admin-Lifecycle |
| Wechsel | Standard maximal 2 Elternwechsel pro Schuljahr, konfigurierbar | ERFÜLLT | zentrale Self-Service-Konfiguration und Lifecycle-Zählung |
| Wechsel | Administrative Wechsel kostenlos und ohne Verbrauch des Elternlimits | ERFÜLLT | Actor-basierte Zählung |
| Wechsel | Option kostenpflichtiger Wechsel später | NACH 1.0 | ausdrücklich als spätere Option geplant |
| Schlösser | Festes Zahlenschloss, Code vom Schüler; Notschlüssel beim Verwalter | ERFÜLLT | organisatorisches Schlossmodell; Notöffnung als Betriebsfall dokumentierbar |
| Rollen | Administrator | ERFÜLLT | lokale Staff-Authentifizierung und Rollenprüfung |
| Rollen | Schließfachverwalter | ERFÜLLT | Staff-Rolle mit Verwaltungsrechten |
| Rollen | Lehrkräfte lesend | ERFÜLLT | IServ/OIDC Teacher-Portal, read-only |
| Eltern | Magic-Link-Anmeldung | ERFÜLLT | verifizierte Elternkontakte, Token und Sitzungen |
| Schüler | IServ/OIDC und Fallback | ERFÜLLT | OIDC-Zuordnung plus Matrikelnummer/Zugangscode-Fallback |
| Lehrkräfte | IServ/OIDC | ERFÜLLT | Rollenclaim-basierte OIDC-Zuordnung |
| Admin/Verwalter | lokale Konten | ERFÜLLT | Passwortauthentifizierung, Sperrung und Sitzungsverwaltung |
| Buchung | Sofort verbindliche Buchung | ERFÜLLT | atomare Umwandlung Reservierung → Buchung |
| Buchung | 15-Minuten-Reservierung | ERFÜLLT | konfigurierbare Reservierungsdauer, Standard 15 Minuten |
| Buchung | Zahlung sperrt Auswahl gegen parallelen Wechsel | ERFÜLLT | `payment_running` und Grace-Window |
| Buchung | Verlängerung behält altes Fach, wenn frei/regelkonform | ERFÜLLT | `RenewalLockerSelector` |
| Buchung | Bei Konflikt Ersatzfach vorschlagen/zuweisen | ERFÜLLT | Eltern- und Admin-Erneuerungslogik |
| Zahlung | Online-Zahlung | ERFÜLLT | Stripe Checkout, Customer-Mapping, signierte und idempotente Webhooks |
| Zahlung | Keine Doppelbelastung bei Wiederaufnahme | ERFÜLLT | Payment-Recovery und konkurrierende Checkout-Sperre |
| Support | Schüler/Eltern melden Defekt und Notöffnung | ERFÜLLT | Support-Self-Service und Incident-Workflow |
| Support | technischer Fachstatus mit Historie | ERFÜLLT | Operations-Modul |
| Daten | Eigenständiges System | ERFÜLLT | eigene FachDock-Datenbank |
| Daten | manueller CSV-Schülerimport | ERFÜLLT | Import mit Vorschau/Profil |
| Daten | CSV-Export Vorname, Nachname, Klasse, Matrikelnummer | ERFÜLLT | Admin-Export |
| Daten | URL-Import vorbereiten | ERFÜLLT | Importarchitektur ist vom konkreten CSV-Transport entkoppelt |
| Daten | spätere REST-API / WPDataAccess JSON | NACH 1.0 | ausdrücklich als spätere Integrationsstufe geplant |
| Betrieb | Installation auf leerem System | ERFÜLLT | Phase-D-Integrationstest `FreshInstallIntegrationTest` |
| Betrieb | Upgrade von freigegebenem v0.9.0 | ERFÜLLT | Phase-D-Integrationstest mit Originalmigrationen aus Git-Tag `v0.9.0` |
| Betrieb | automatische Migrationen | ERFÜLLT | versionierter `MigrationRunner` und Idempotenztest |
| Betrieb | Vollbackup und Restore | ERFÜLLT | Phase C, SHA-256, Pfadvalidierung und vollständige Restore-Semantik |
| Betrieb | Sicherheitsbackup vor Restore und Update | ERFÜLLT | Phase C |
| Betrieb | automatischer Datenschutzlauf | ERFÜLLT | `privacy:tick` mit System-Actor |
| Betrieb | Überwachung von Mail-, Schuljahres-, Datenschutzjob und Backups | ERFÜLLT | Systemstatus und Produktionscheck |
| Sicherheit | CSRF bei mutierenden Webaktionen | ERFÜLLT | zentrale CSRF-Prüfung in Controllern |
| Sicherheit | Secrets nicht in regulärer Konfiguration | ERFÜLLT | `secrets.local.php` und geschützte Konfigurationsoberflächen |
| Sicherheit | Security-Header / CSP / HSTS bei HTTPS | ERFÜLLT | Production Hardening |
| Datenschutz | Aufbewahrung/Anonymisierung | ERFÜLLT | konfigurierbare Retention und automatisierter Lauf |
| UX | responsive zentrale Navigation | ERFÜLLT | Desktop-/Mobilnavigation |
| UX | sichtbare Fehlermeldungen und Empty States | ERFÜLLT | Admin-, Eltern-, Buchungs-, Zahlungs- und Lageplanansichten |
| UX | Tastaturfokus, Hauptinhalt-Sprunglink, reduzierte Bewegung | ERFÜLLT | globale Phase-D-Accessibility-Assets |
| UX | Schutz vor versehentlichem Beenden/Stornieren | ERFÜLLT | globale Bestätigungsabfrage vor irreversiblen Buchungsaktionen |

## 1.0-Blocker

Zum Abschluss der Phase D dürfen keine mit `OFFEN` oder `TEILWEISE` markierten Pflichtanforderungen verbleiben. Die einzigen nicht in 1.0 enthaltenen Punkte der Matrix sind Funktionen, die bereits in der ursprünglichen Planung ausdrücklich als spätere Erweiterungen festgelegt wurden.

## Technische Release-Gates

Vor einem 1.0.0-Release müssen alle folgenden Gates grün sein:

1. PHP-Syntaxprüfung aller produktiven und Test-PHP-Dateien.
2. Vollständige PHPUnit-Suite auf MySQL 8.4.
3. PHPStan ohne Fehler.
4. PHP CS Fixer ohne Abweichungen.
5. Neuinstallation in eine leere Datenbank einschließlich Admin-/Konfigurationsanlage.
6. Upgrade einer mit den Originalmigrationen des Tags `v0.9.0` erzeugten Datenbank auf den aktuellen Stand ohne Verlust vorhandener Daten.
7. Idempotenter zweiter Migrationslauf nach dem Upgrade.
8. Durchgängiger Buchungspfad Auswahl → Reservierung → Zahlung → Buchung → Fachwechsel → Verlängerung.
9. Vollbackup-/Restore-Integrationstest einschließlich Manipulationserkennung.
10. Keine stabile Versionsnummer `1.0.0` und kein 1.0.0-Tag vor abgeschlossener Release-Candidate-Abnahme.
