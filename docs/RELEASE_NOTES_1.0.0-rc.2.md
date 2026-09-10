# FachDock 1.0.0-rc.2

`1.0.0-rc.2` ist der zweite Release Candidate für FachDock 1.0. Er enthält die Korrekturen und Bedienverbesserungen aus der manuellen Abnahme von `1.0.0-rc.1` und ist für die nächste produktionsnahe Testphase vorgesehen.

## Änderungen gegenüber 1.0.0-rc.1

### Navigation und mobile Bedienung

- Desktop-Dropdowns im Header schließen wieder zuverlässig, wenn der Menübereich mit der Maus verlassen wird; eine kurze Verzögerung erleichtert den Wechsel vom Button in das geöffnete Menü.
- Die Aktionen auf `/parent/login` überlappen nicht mehr.
- Die Dashboard-Aktionen „Schließfachbetrieb öffnen“ und „Systemstatus prüfen“ werden auf schmalen Displays untereinander dargestellt.
- Die Filterbuttons in der Kachel „Vorgänge“ auf `/admin/operations` erhalten einen definierten Abstand und stehen mobil untereinander in voller Breite.
- Die Checkbox „Für neue Buchungen freigeben“ wird im Schließfachstatus und in der Vorgangsdetailbearbeitung direkt neben ihrem Text dargestellt.

### Konfiguration und E-Mail

- Nach dem Speichern lokaler Einstellungen werden Stripe-, SMTP-, OIDC-, Buchungs- und andere Konfigurationswerte unmittelbar aktuell angezeigt. Browsercache, PHP-Dateistatus und OPcache werden dabei berücksichtigt.
- Die E-Mail-Konfiguration erklärt Worker-Batch, rollierendes 60-Minuten-Limit, Retry-Abstände und Processing-Timeout verständlicher und zeigt den letzten erfolgreichen Mail-Worker-Lauf an.
- Der erforderliche externe Aufruf `php bin/fachdock mail:work` wird in der Oberfläche deutlich genannt.
- Eltern-Magic-Links und E-Mail-Bestätigungslinks werden als Sofortmails behandelt: Sie werden zunächst sicher in der Queue gespeichert und anschließend bereits im auslösenden Webrequest zu versenden versucht.
- Erfolgreiche Sofortmails zählen vollständig gegen das gemeinsame rollierende 60-Minuten-Gesamtlimit.
- Eine konfigurierbare Reserve für Sofortmails verhindert, dass normale Warteschlangen-Mails die gesamte Stundenkapazität vor zeitkritischen Mails belegen. Der Standardwert beträgt 10 reservierte Plätze innerhalb eines Gesamtlimits von 50.
- Ist das Gesamtlimit vollständig ausgeschöpft oder schlägt SMTP fehl, bleibt die Sofortmail in der Queue und wird über den regulären Worker/Retry-Pfad weiterbehandelt.

### Schülerimport

- Checkboxen im Schülerimport stehen sauber neben ihren Beschreibungstexten.
- Fehler in der CSV-Importvorschau werden als verständliche Meldung auf der normalen Importseite angezeigt, statt als HTTP-422-Antwort von Webservern oder Proxys durch eine generische Fehlerseite ersetzt werden zu können.
- Ohne gespeichertes Importprofil erkennt FachDock Semikolon, Komma oder Tabulator automatisch anhand der Kopfzeile. Die manuelle Auswahl bleibt der bevorzugte Ausgangswert.
- Ein Integrationstest deckt den vollständigen Preview-Pfad Upload → Staging → Parsing → MySQL-Vergleich → gerenderte Vorschauseite ab.

### Lagepläne

- `/admin/floorplans` verwendet die gleiche maximale Inhaltsbreite und die gleichen Seitenränder wie die übrigen Admin-Seiten.
- Der Lageplan-Zoom bleibt innerhalb des vorgesehenen Scrollbereichs; die Karte wächst beim Vergrößern nicht mehr über ihren Außenrand hinaus.
- Bereits platzierte Schrankgruppen können im Lageplan verschoben und über einen Ziehpunkt in Breite und Höhe angepasst werden.
- Noch nicht platzierte, bereits vorhandene Schrankgruppen können nach Auswahl direkt durch Aufziehen eines Rechtecks positioniert werden.
- Die strukturelle Anlage neuer Schrankgruppen mit Bereich, Korpussen und Fächern bleibt weiterhin unter „Standorte“; der Lageplan verwaltet Position und Geometrie.

## Release-Gates

Der Release Candidate wird nur veröffentlicht, wenn die vollständige CI grün ist. Sie umfasst insbesondere:

- Neuinstallation in eine leere MySQL-Datenbank,
- Upgrade einer mit den Originalmigrationen von `v0.9.0` erzeugten Datenbank,
- Erhalt vorhandener Daten und idempotenten zweiten Migrationslauf,
- End-to-End-Buchungsweg einschließlich Stripe, Fachwechsel und Verlängerung,
- Backup-/Restore-Integrationstest einschließlich Manipulationserkennung,
- Regressionstests für die rc.1-Abnahmebefunde,
- PHPStan und PHP-CS-Fixer.

## Empfohlene Abnahme

Für `rc.2` sollten insbesondere die in `rc.1` auffällig gewordenen Bereiche erneut geprüft werden:

1. Neuinstallation bzw. manuelle Aktualisierung einer separaten Testinstallation.
2. Responsive Navigation, Dashboard, Elternlogin und `/admin/operations` auf einem Mobilgerät.
3. CSV-Schülerimport mit Semikolon-, Komma- und gegebenenfalls Tabulator-Dateien sowie absichtlich fehlerhafter CSV.
4. Lageplan-Zoom, Verschieben, Größenänderung und Rechteckplatzierung.
5. SMTP, Magic Links, E-Mail-Bestätigungslinks, Mail-Worker und Stundenlimit/Reserve.
6. Stripe im Testmodus, Buchung, Zahlung, BuT, Fachwechsel und Verlängerung.
7. IServ/OIDC in der realen Zielumgebung.
8. Backup, Restore und Produktionsbereitschaftsprüfung.

Die vollständige Checkliste befindet sich in `docs/RELEASE_CANDIDATE.md`.

## Hinweis zum Updatekanal

Dieser Release wird auf GitHub als **Prerelease** markiert. Der integrierte FachDock-Updater berücksichtigt weiterhin ausschließlich stabile Releases. `1.0.0-rc.2` wird bestehenden Installationen daher nicht automatisch als normales Produktivupdate angeboten.

## Nicht Bestandteil von 1.0

Die bereits bewusst nach 1.0 verschobenen Erweiterungen bleiben unverändert:

- optional kostenpflichtige Fachwechsel,
- REST-/WPDataAccess-API für spätere automatisierte Stammdatenintegration,
- weitergehende PDF-/Dokumentenfunktionen.
