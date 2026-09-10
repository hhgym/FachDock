# FachDock 1.0 – Release-Candidate-Abnahme

`1.0.0-rc.1` ist der erste installierbare Release Candidate für FachDock 1.0. Die technische Phase-D-Abnahme ist abgeschlossen; mit diesem RC folgt die reale Abnahme in einer produktionsnahen Testumgebung. Erst danach wird `1.0.0` stabil freigegeben oder bei notwendigen Änderungen ein weiterer Release Candidate erstellt.

## Automatisch geprüfte Kriterien

Die CI muss für `1.0.0-rc.1` vollständig grün sein:

- Composer-Konfiguration ist gültig.
- PHP-Syntax ist fehlerfrei.
- PHPUnit läuft gegen MySQL 8.4.
- PHPStan meldet keine Fehler.
- PHP CS Fixer meldet keine Abweichungen.
- der Git-Tag `v0.9.0` ist als Upgrade-Ausgangsbasis verfügbar.
- eine FachDock-Neuinstallation in einer leeren Datenbank funktioniert vollständig.
- die Upgrade-Migrationen von einer mit `v0.9.0` erzeugten Datenbank auf den RC-Stand funktionieren und bewahren vorhandene Daten.
- der erneute Migrationslauf nach dem Upgrade ist idempotent.
- der End-to-End-Pfad Buchungsauswahl → Reservierung → Stripe-Zahlung → Buchung → Fachwechsel → Verlängerung funktioniert.
- Backup und Restore einschließlich SHA-256-Manipulationserkennung funktionieren.
- automatisierte Datenschutzläufe funktionieren ohne fingierten Staff-Akteur.
- Buchungs- und Zahlungslisten funktionieren auch oberhalb früherer fester Ergebnisgrenzen serverseitig paginiert.
- das erzeugte Release-ZIP besteht Integritäts- und Strukturprüfungen und enthält keine lokalen Secrets oder Testdateien.

## Release-Artefakte

Der GitHub-Prerelease muss enthalten:

- `FachDock-1.0.0-rc.1.zip`
- `FachDock-1.0.0-rc.1.zip.sha256`

Das ZIP muss als Anwendungswurzel genau einen Ordner `FachDock-1.0.0-rc.1/` enthalten. Darin müssen insbesondere `public/`, `src/`, `config/`, `migrations/`, `templates/`, `bin/`, `vendor/`, `docs/` und `LICENSE` vorhanden sein. Lokale Dateien wie `config/app.local.php` und `config/secrets.local.php` dürfen nicht enthalten sein.

## Bedienabnahme

Vor dem finalen 1.0.0-Release sind die folgenden Ansichten auf Desktop und Mobilgerät zu prüfen:

- [ ] Administrator-Dashboard und zentrale Navigation
- [ ] Standorte, Schrankgruppen, Korpusse und Lagepläne
- [ ] Schülerimport und Elternverwaltung
- [ ] Buchungs- und Zahlungsverwaltung einschließlich Pagination
- [ ] BuT-Prüfung
- [ ] Elternportal einschließlich Buchung per Liste und Lageplan
- [ ] Eltern-Self-Service für Fachwechsel und Verlängerung
- [ ] Schüler-Support und Lehrkräfte-Portal
- [ ] OIDC-Konfiguration und Login-Auswahl
- [ ] Systemstatus, Datenschutz/Produktionscheck und Update-Seite

Bei der Tastaturprüfung muss der Hauptinhalt per Sprunglink erreichbar sein, der Fokus sichtbar bleiben und die Schrankgruppenmarker des Lageplans müssen mit Tab sowie Pfeiltasten/Home/End erreichbar sein. Beenden und Stornieren einer Buchung müssen vor dem Absenden eine zusätzliche Bestätigung verlangen.

## Funktionsabnahme

- [ ] Neuinstallation aus dem RC-ZIP in einer leeren Datenbank
- [ ] Administrator anlegen und anmelden
- [ ] Schüler per CSV importieren
- [ ] Elternkontakt verknüpfen und Magic Link verwenden
- [ ] Schließfach per Liste buchen
- [ ] Schließfach per Lageplan buchen
- [ ] Stripe-Testzahlung erfolgreich abschließen
- [ ] fehlgeschlagene/abgebrochene Stripe-Zahlung nachvollziehen
- [ ] BuT-Befreiung genehmigen und ablehnen
- [ ] kostenloses Schließfach wechseln
- [ ] Wechselgrenze prüfen
- [ ] Buchung verlängern
- [ ] Übergang Klasse 6 → 7 mit notwendigem Fachwechsel prüfen
- [ ] Defekt melden und bearbeiten
- [ ] Notöffnung dokumentieren
- [ ] Buchung beenden bzw. stornieren

## Identitäts- und Kommunikationsabnahme

- [ ] IServ/OIDC-Schülerlogin
- [ ] IServ/OIDC-Lehrkräftelogin mit nur lesendem Zugriff
- [ ] manuelle OIDC-Zuordnung eines nicht automatisch gefundenen Kontos
- [ ] Schüler-Fallback mit Matrikelnummer/Zugangscode
- [ ] SMTP-Testversand
- [ ] Mail-Worker verarbeitet Queue
- [ ] Buchungs-, Zahlungs-, BuT- und Lifecycle-Mails werden korrekt erzeugt

## Betriebsabnahme

Für die Zielumgebung sind vor der stabilen Freigabe zu kontrollieren:

- [ ] öffentliche HTTPS-Basis-URL
- [ ] SMTP-Absender und erfolgreicher Mail-Worker
- [ ] Stripe-Konfiguration im Testmodus; Live-Modus erst nach gesonderter Freigabe
- [ ] OIDC-Discovery/Client-Konfiguration für IServ
- [ ] `storage/` und lokale Konfiguration sind beschreibbar, aber nicht öffentlich auslieferbar
- [ ] Zeitjobs `mail:work`, `school-year:tick`, `privacy:tick` und `backup:create` laufen
- [ ] Systemstatus zeigt frische Worker-Heartbeats und ein aktuelles Backup
- [ ] Vollbackup wurde erzeugt und extern gesichert
- [ ] Restore wurde in einer Testumgebung erfolgreich durchgeführt
- [ ] Produktionscheck enthält keine unbehandelten Fehler; Warnungen wurden bewusst bewertet

## Updatekanal

`1.0.0-rc.1` wird als GitHub-**Prerelease** veröffentlicht. Der normale integrierte FachDock-Updater berücksichtigt weiterhin ausschließlich stabile Releases. Der RC wird bestehenden Installationen daher nicht automatisch als produktives Update angeboten.

Der Upgradepfad von `v0.9.0` auf den aktuellen RC-Datenbankstand wird automatisiert in der CI geprüft. Für reale RC-Tests mit vorhandenen Daten ist vor jeder manuellen Aktualisierung ein Vollbackup zu erstellen.

## Entscheidung nach der Abnahme

- **Keine Blocker:** Release-Branch für `1.0.0`, finaler Versionsbump, stabile CI und Veröffentlichung von `v1.0.0`.
- **Blocker gefunden:** Fehler auf `develop` beheben und anschließend `1.0.0-rc.2` veröffentlichen.
- **Nur kleinere nicht blockierende Punkte:** für 1.0 bewusst bewerten und gegebenenfalls in die Nach-1.0-Planung übernehmen.

Der verbindliche Funktionsumfang ist zusätzlich in `docs/REQUIREMENTS_AUDIT_1.0.md` dokumentiert.