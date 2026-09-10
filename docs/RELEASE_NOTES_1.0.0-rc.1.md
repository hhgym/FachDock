# FachDock 1.0.0-rc.1

`1.0.0-rc.1` ist der erste Release Candidate für FachDock 1.0. Er bündelt den für 1.0 vorgesehenen Funktionsumfang und dient der produktionsnahen Abnahme vor der stabilen Freigabe.

## Schwerpunkte des Release Candidates

- IServ/OIDC-Anmeldung für Schülerinnen und Schüler sowie Lehrkräfte einschließlich PKCE, strikter Discovery-Prüfung, Rollenmapping und manueller Zuordnung.
- Vollständiger Eltern-Self-Service für bestehende Buchungen, regelkonforme kostenlose Fachwechsel und Verlängerungen.
- Verlängerungslogik mit bevorzugter Wiederverwendung des bisherigen Faches und verpflichtendem Wechsel beim Übergang von Klasse 6 nach 7.
- Interaktive Lageplanbuchung mit Live-Verfügbarkeit, Empfehlungen, Barrierearmut, Reservierungsstatus und alternativer Listenansicht.
- Vollbackup und vollständiger Restore einschließlich Datenbank, lokaler Konfiguration und persistentem Storage sowie SHA-256-Prüfung.
- Automatisierte Betriebsjobs für Datenschutz, Schuljahreswechsel, Mailversand und Backups mit Systemstatus und Produktionsbereitschaftsprüfung.
- Gehärteter Self-Update-Prozess mit Sicherheitsbackup, Wartungsmodus und vollständigem Rollback bei Fehlern.
- Serverseitige Pagination für große Buchungs- und Zahlungsbestände.
- Globale Accessibility-Härtung mit Hauptinhalt-Sprunglink, sichtbarem Tastaturfokus, reduzierter Bewegung und Tastaturbedienung der Lageplanmarker.
- Zusätzlicher Schutz vor versehentlichem Beenden oder Stornieren von Buchungen.

## Release-Gates

Der Release Candidate wird nur erzeugt, wenn die vollständige CI grün ist. Sie umfasst insbesondere:

- Neuinstallation in eine leere MySQL-Datenbank,
- Upgrade einer mit den Originalmigrationen von `v0.9.0` erzeugten Datenbank,
- Erhalt vorhandener Daten und idempotenten zweiten Migrationslauf,
- End-to-End-Pfad Auswahl → Reservierung → Stripe-Zahlung → Buchung → Fachwechsel → Verlängerung,
- Backup-/Restore-Integrationstest einschließlich Manipulationserkennung,
- PHPStan und PHP-CS-Fixer.

## Empfohlene Abnahme

Der RC sollte zunächst in einer separaten Testinstallation eingesetzt werden. Zu prüfen sind insbesondere:

1. Neuinstallation aus `FachDock-1.0.0-rc.1.zip`.
2. Rollen und Anmeldewege für Administrator, Schließfachverwaltung, Lehrkraft, Schüler und Eltern.
3. Buchung per Liste und Lageplan, Stripe im Testmodus und BuT-Prüfung.
4. Fachwechsel und Verlängerung einschließlich Klassenübergang 6 → 7.
5. SMTP-Mailversand und Hintergrundjobs.
6. IServ/OIDC in der realen Zielumgebung.
7. Defekt-/Notöffnungsworkflow.
8. Backup, Restore und Produktionsbereitschaftsprüfung.
9. Desktop-, Mobil- und Tastaturbedienung.

## Hinweis zum Updatekanal

Dieser Release wird auf GitHub als **Prerelease** markiert. Der integrierte FachDock-Updater berücksichtigt standardmäßig weiterhin ausschließlich stabile Releases. Damit wird `1.0.0-rc.1` bestehenden Installationen nicht versehentlich als produktives Update angeboten.

## Nicht Bestandteil von 1.0

Die bereits bewusst nach 1.0 verschobenen Erweiterungen bleiben unverändert:

- optional kostenpflichtige Fachwechsel,
- REST-/WPDataAccess-API für spätere automatisierte Stammdatenintegration,
- weitergehende PDF-/Dokumentenfunktionen.

Fehler oder Abweichungen aus der RC-Abnahme sollen vor `1.0.0` behoben werden; bei notwendigen Änderungen folgt gegebenenfalls ein weiterer Release Candidate.