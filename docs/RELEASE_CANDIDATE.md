# FachDock 1.0 – Release-Candidate-Abnahme

Phase D bereitet den ersten stabilen 1.0.0-Release vor. Der Branch `develop` bleibt während dieser Phase weiterhin auf der zuletzt veröffentlichten stabilen Versionsnummer; die Umstellung auf `1.0.0`, der Release-PR nach `main`, das Tag und die Release-Artefakte erfolgen erst nach abgeschlossener RC-Abnahme.

## Automatisch geprüfte Kriterien

Die CI muss vor dem Merge der Phase D vollständig grün sein:

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

## Bedienabnahme

Vor dem finalen 1.0.0-Release sind die folgenden Ansichten auf Desktop und Mobilgerät stichprobenartig zu prüfen:

- Administrator-Dashboard und zentrale Navigation
- Standorte, Schrankgruppen, Korpusse und Lagepläne
- Schülerimport und Elternverwaltung
- Buchungs- und Zahlungsverwaltung
- BuT-Prüfung
- Elternportal einschließlich Buchung per Liste und Lageplan
- Eltern-Self-Service für Fachwechsel und Verlängerung
- Schüler-Support und Lehrkräfte-Portal
- OIDC-Konfiguration und Login-Auswahl
- Systemstatus, Datenschutz/Produktionscheck und Update-Seite

Bei der Tastaturprüfung muss der Hauptinhalt per Sprunglink erreichbar sein, der Fokus sichtbar bleiben und die Schrankgruppenmarker des Lageplans müssen mit Tab sowie Pfeiltasten/Home/End erreichbar sein. Beenden und Stornieren einer Buchung müssen vor dem Absenden eine zusätzliche Bestätigung verlangen.

## Betriebsabnahme

Für die Zielumgebung sind vor der Freigabe zu kontrollieren:

- öffentliche HTTPS-Basis-URL
- SMTP-Absender und erfolgreicher Mail-Worker
- Stripe-Konfiguration im gewünschten Test-/Live-Modus
- OIDC-Discovery/Client-Konfiguration, sofern IServ verwendet wird
- schreibbare, aber nicht öffentlich ausgelieferte `storage/`- und Konfigurationsbereiche
- laufende Zeitjobs `mail:work`, `school-year:tick`, `privacy:tick` und `backup:create`
- aktuelles extern gesichertes Vollbackup
- Produktionscheck ohne Fehler; Warnungen müssen bewusst bewertet sein

## Freigabeschritt nach Phase D

Erst wenn die Phase-D-CI grün ist und die Bedien-/Betriebsabnahme keine Blocker ergibt, wird ein eigener Release-Branch für `1.0.0` angelegt. Dort werden Versionsnummer und Release-Dokumentation auf `1.0.0` gesetzt, anschließend wird gegen `main` gemergt und der bestehende Release-Workflow erzeugt ZIP und SHA-256-Prüfsumme.
