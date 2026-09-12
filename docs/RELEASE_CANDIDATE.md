# FachDock 1.0 – Release-Candidate-Abnahme

`1.0.0-rc.3` ist der dritte installierbare Release Candidate für FachDock 1.0. Er baut auf `1.0.0-rc.2` auf und erweitert die produktionsnahe Abnahme insbesondere um Updatekanäle, OpenID-Connect-Test und -Zuordnung sowie den Lebenszyklus von Schüler- und Elternkonten.

## Automatisch geprüfte Kriterien

Die CI muss für `1.0.0-rc.3` vollständig grün sein:

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
- automatisierte Datenschutz- und Account-Lifecycle-Läufe funktionieren ohne fingierten Staff-Akteur.
- Schülerkonten durchlaufen Nachlauf, automatische Sperre, Rückkehr und Anonymisierung regelkonform; manuelle Sperren bleiben bei Imports erhalten.
- Elternkonten starten ihre Frist erst nach Wegfall des letzten aktiven Kindes und berücksichtigen dabei den jeweils jüngsten relevanten Übergang.
- die konfigurierbare OpenID-Connect-Schülerzuordnung wird einschließlich Nachlaufphase getestet.
- Buchungs- und Zahlungslisten funktionieren auch oberhalb früherer fester Ergebnisgrenzen serverseitig paginiert.
- das erzeugte Release-ZIP besteht Integritäts- und Strukturprüfungen und enthält keine lokalen Secrets oder Testdateien.
- die RC-Regressionstests für responsive Admin-Ansichten, CSV-Importvorschau, Mail-Sofortversand/-Reserve, Lageplanbearbeitung und Identitätsverwaltung laufen erfolgreich.

## Release-Artefakte

Der GitHub-Prerelease muss enthalten:

- `FachDock-1.0.0-rc.3.zip`
- `FachDock-1.0.0-rc.3.zip.sha256`

Das ZIP muss als Anwendungswurzel genau einen Ordner `FachDock-1.0.0-rc.3/` enthalten. Darin müssen insbesondere `public/`, `src/`, `config/`, `migrations/`, `templates/`, `bin/`, `vendor/`, `docs/` und `LICENSE` vorhanden sein. Lokale Dateien wie `config/app.local.php` und `config/secrets.local.php` dürfen nicht enthalten sein.

## Bedienabnahme

Vor dem finalen 1.0.0-Release sind die folgenden Ansichten auf Desktop und Mobilgerät zu prüfen:

- [ ] Administrator-Dashboard und zentrale Navigation
- [ ] Standorte, Schrankgruppen, Korpusse und Lagepläne
- [ ] Schülerdaten, Schülerimport, Elternverwaltung und Benutzerkonten
- [ ] lokale Benutzerverwaltung einschließlich Deaktivierung und Reaktivierung
- [ ] Buchungs- und Zahlungsverwaltung einschließlich Pagination
- [ ] BuT-Prüfung
- [ ] Elternportal einschließlich Buchung per Liste und Lageplan
- [ ] Eltern-Self-Service für Fachwechsel und Verlängerung
- [ ] Schüler-Support und Lehrkräfte-Portal
- [ ] OpenID-Connect-Konfiguration, Testlogin, Claim-Anzeige und Login-Auswahl
- [ ] Systemstatus, Datenschutz/Produktionscheck und Update-Seite einschließlich Kanalwahl

Bei der Tastaturprüfung muss der Hauptinhalt per Sprunglink erreichbar sein, der Fokus sichtbar bleiben und die Schrankgruppenmarker des Lageplans müssen mit Tab sowie Pfeiltasten/Home/End erreichbar sein. Beenden und Stornieren einer Buchung müssen vor dem Absenden eine zusätzliche Bestätigung verlangen.

## Besondere Nachprüfung seit rc.2

- [ ] Der OpenID-Connect-Discovery-Test funktioniert auch vor der produktiven Aktivierung.
- [ ] Der echte OpenID-Connect-Testlogin verwendet PKCE und zeigt die tatsächlich übertragenen UserInfo-Claims, ohne Identität, Session, Token oder Schülerzuordnung dauerhaft anzulegen.
- [ ] Schülerrollen können konfiguriert werden; die automatische Zuordnung kann über einen frei konfigurierbaren Claim und das Zielfeld E-Mail oder Matrikelnummer erfolgen.
- [ ] Bei Nutzung von `untis_username` kann `iserv:untis` als Scope ergänzt und der tatsächliche Claim-Wert über den Testlogin geprüft werden.
- [ ] Der Login-Button ist providerneutral beschriftbar und Login/Dashboard funktionieren auf Desktop und Mobilgeräten.
- [ ] Stable ist der voreingestellte Updatekanal; der RC-Kanal zeigt freigegebene Release Candidates nur nach Freischaltung.
- [ ] Der optionale Develop-Kanal verwendet den rolling Build aus `develop-build` und führt keine automatischen Downgrades durch.
- [ ] Schüler werden beim Wechsel auf inaktiv nicht sofort ausgesperrt, sondern standardmäßig erst nach 30 Tagen deaktiviert.
- [ ] Nach einem Jahr werden inaktive Schüler standardmäßig anonymisiert; beide Schülerfristen sind konfigurierbar.
- [ ] Kehrt ein Schüler zurück, wird eine automatisch gesetzte Lifecycle-Sperre aufgehoben, eine manuelle Sperre dagegen nicht.
- [ ] Eltern bleiben aktiv, solange mindestens ein aktives Kind verknüpft ist; danach beginnt standardmäßig die dreijährige Frist für Deaktivierung und Anonymisierung.
- [ ] Das Ende einer Eltern-Kind-Verknüpfung kann den Fristbeginn bestimmen, wenn es das jüngste relevante Ereignis ist.
- [ ] Eine sofortige manuelle Anonymisierung ist nur nach Eingabe von `ANONYMISIEREN <ID>` möglich.
- [ ] Schülerstammdaten und Benutzerkonten werden getrennt dargestellt; vorhandene Benutzerkonten sind aus der Schülerdatenansicht direkt erreichbar.

## Fortbestehende Regressionen aus rc.1/rc.2

- [ ] Desktop-Header-Dropdowns schließen nach Verlassen mit der Maus zuverlässig.
- [ ] Elternlogin-Aktionen überlappen auch auf schmalen Displays nicht.
- [ ] Gespeicherte Stripe-/SMTP-/andere lokale Einstellungen werden unmittelbar aktuell angezeigt.
- [ ] CSV-Schülerimport zeigt die Vorschau zuverlässig; CSV- oder Mappingfehler erscheinen als verständliche Meldung auf der Importseite.
- [ ] Ohne Importprofil werden Semikolon, Komma und Tabulator als Trennzeichen robust erkannt.
- [ ] Dashboard- und Vorgangsbuttons überlappen auf Mobilgeräten nicht.
- [ ] Checkboxen beim Schülerimport und unter `/admin/operations` stehen sauber neben ihrem Text.
- [ ] Lageplan-Zoom bleibt innerhalb der Karte/des Scrollbereichs.
- [ ] Bestehende Schrankgruppen können verschoben sowie in Breite und Höhe angepasst werden.
- [ ] Noch nicht platzierte Schrankgruppen können per aufgezogenem Rechteck positioniert werden.
- [ ] Mail-Konfigurationsseite erläutert Queue/Worker verständlich und zeigt den letzten erfolgreichen Worker-Lauf.
- [ ] Magic- und E-Mail-Bestätigungslinks werden sofort versucht zu versenden.
- [ ] Sofortmails zählen gegen das gemeinsame 60-Minuten-Limit; normale Queue-Mails respektieren die konfigurierte Sofortmail-Reserve.

## Funktionsabnahme

- [ ] Neuinstallation aus dem RC-ZIP in einer leeren Datenbank
- [ ] Upgrade einer bestehenden RC-/Testinstallation
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

- [ ] OpenID-Connect-Schülerlogin
- [ ] OpenID-Connect-Lehrkräftelogin mit nur lesendem Zugriff
- [ ] echter OpenID-Connect-Testlogin und Kontrolle der übertragenen Claims
- [ ] automatische Schülerzuordnung über Rolle/Claim/Zielfeld
- [ ] manuelle OpenID-Connect-Zuordnung eines nicht automatisch gefundenen Kontos
- [ ] Schüler-Fallback mit Matrikelnummer/Zugangscode während des Nachlaufs und Sperre nach Deaktivierung
- [ ] SMTP-Testversand
- [ ] Mail-Worker verarbeitet Queue
- [ ] Buchungs-, Zahlungs-, BuT- und Lifecycle-Mails werden korrekt erzeugt

## Betriebsabnahme

Für die Zielumgebung sind vor der stabilen Freigabe zu kontrollieren:

- [ ] öffentliche HTTPS-Basis-URL
- [ ] SMTP-Absender und erfolgreicher Mail-Worker
- [ ] Stripe-Konfiguration im Testmodus; Live-Modus erst nach gesonderter Freigabe
- [ ] OpenID-Connect-Discovery/Client-Konfiguration für den eingesetzten Provider
- [ ] benötigte Scopes und Claims, insbesondere optional `iserv:untis`/`untis_username`, sind im Provider freigegeben
- [ ] `storage/` und lokale Konfiguration sind beschreibbar, aber nicht öffentlich auslieferbar
- [ ] Zeitjobs `mail:work`, `school-year:tick`, `privacy:tick` und `backup:create` laufen
- [ ] Systemstatus zeigt frische Worker-Heartbeats und ein aktuelles Backup
- [ ] Vollbackup wurde erzeugt und extern gesichert
- [ ] Restore wurde in einer Testumgebung erfolgreich durchgeführt
- [ ] Produktionscheck enthält keine unbehandelten Fehler; Warnungen wurden bewusst bewertet

## Updatekanäle

`1.0.0-rc.3` wird als GitHub-**Prerelease** veröffentlicht. **Stable** bleibt der voreingestellte Kanal und bietet diesen RC nicht als normales Produktivupdate an.

Für Testinstallationen kann der **RC-Kanal** freigeschaltet werden. Er berücksichtigt stabile Releases und veröffentlichte `-rc.N`-Versionen. Zusätzlich kann ein **Develop-Kanal** freigeschaltet werden; dieser bezieht rolling Test-Builds aus dem Branch `develop-build` und identifiziert sie über den jeweiligen Commit-SHA. Ein automatischer Downgrade wird nicht durchgeführt.

Der Upgradepfad von `v0.9.0` auf den aktuellen RC-Datenbankstand wird automatisiert in der CI geprüft. Für reale RC-Tests mit vorhandenen Daten ist vor jeder manuellen Aktualisierung ein Vollbackup zu erstellen.

## Entscheidung nach der Abnahme

- **Keine Blocker:** Release-Branch für `1.0.0`, finaler Versionsbump, stabile CI und Veröffentlichung von `v1.0.0`.
- **Weitere Blocker gefunden:** Fehler auf `develop` beheben und bei Bedarf einen weiteren Release Candidate veröffentlichen.
- **Nur kleinere nicht blockierende Punkte:** für 1.0 bewusst bewerten und gegebenenfalls in die Nach-1.0-Planung übernehmen.

Der verbindliche Funktionsumfang ist zusätzlich in `docs/REQUIREMENTS_AUDIT_1.0.md` dokumentiert.
