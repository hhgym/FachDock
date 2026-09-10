# Betrieb und Automatisierung

FachDock stellt für den regulären Betrieb mehrere CLI-Kommandos unter `php bin/fachdock` bereit.

## Empfohlene Jobs

- `mail:work`: regelmäßig, z. B. jede Minute oder alle fünf Minuten.
- `school-year:tick`: täglich. Versendet fällige Verlängerungserinnerungen und führt ab dem 1. August den vorgesehenen Roll-over aus.
- `privacy:tick`: täglich oder wöchentlich. Wendet die unter **Datenschutz & Produktionscheck** konfigurierten Aufbewahrungsfristen an.
- `backup:create`: mindestens täglich sowie zusätzlich vor größeren administrativen Eingriffen.

Die konkrete Zeitplanung erfolgt bewusst außerhalb von FachDock, typischerweise per Cron oder systemd timer. Dadurch bleibt der Betrieb auch dann kontrollierbar, wenn der Webprozess nicht läuft.

## Backup

`php bin/fachdock backup:create`

erstellt unter `storage/backups/` ein ZIP-Archiv und eine gleichnamige `.sha256`-Datei. Gesichert werden:

- alle Datenbanktabellen einschließlich Schema und Daten,
- `config/app.local.php`, sofern vorhanden,
- `config/secrets.local.php`, sofern vorhanden,
- persistente Dateien unter `storage/`, insbesondere Lagepläne.

Temporäre Update-Verzeichnisse, alte Backups und die Wartungsdatei werden nicht rekursiv mitgesichert.

Da ein Vollbackup auch lokale Secrets enthalten kann, darf `storage/` nicht öffentlich über den Webserver ausgeliefert werden. Backups sollten zusätzlich regelmäßig auf ein separates, zugriffsgeschütztes Speichersystem kopiert werden.

## Restore

`php bin/fachdock backup:restore <datei> --force`

Vor jedem Restore erzeugt FachDock automatisch ein aktuelles Sicherheitsbackup. Anschließend wird die SHA-256-Prüfsumme geprüft, sofern die zugehörige `.sha256`-Datei vorhanden ist. Wiederhergestellt werden nur bekannte Datenbankbestandteile sowie explizit erlaubte Pfade unter `config/` und `storage/`.

Ein Restore ist absichtlich ausschließlich über die CLI möglich und verlangt `--force`.

## Updates

Vor einem Self-Update erzeugt FachDock automatisch ein Vollbackup. Scheitert Dateiaustausch oder Migration, versucht FachDock sowohl die ersetzten Programmdateien als auch die Datenbank auf den vorherigen Stand zurückzusetzen. Das verwendete Vollbackup wird in den Update-Metadaten protokolliert.

## Datenschutzautomation

`privacy:tick` führt dieselbe Aufbewahrungs- und Anonymisierungslogik aus wie der manuelle Administratorlauf. Automatische Läufe werden in der Historie als solche gekennzeichnet und über den Worker-Heartbeat überwacht.
