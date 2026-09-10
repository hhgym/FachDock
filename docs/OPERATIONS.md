# Betrieb und Automatisierung

FachDock stellt für den regulären Betrieb mehrere CLI-Kommandos unter `php bin/fachdock` bereit.

## Empfohlene Jobs

- `mail:work`: regelmäßig, empfohlen jede Minute; spätestens alle fünf Minuten.
- `school-year:tick`: täglich. Stellt fällige Verlängerungserinnerungen in die Mail-Warteschlange und führt ab dem 1. August den vorgesehenen Roll-over aus.
- `privacy:tick`: täglich oder wöchentlich. Wendet die unter **Datenschutz & Produktionscheck** konfigurierten Aufbewahrungsfristen an.
- `backup:create`: mindestens täglich sowie zusätzlich vor größeren administrativen Eingriffen.

Die konkrete Zeitplanung erfolgt bewusst außerhalb von FachDock, typischerweise per Cron oder systemd timer. Dadurch bleibt der Betrieb auch dann kontrollierbar, wenn der Webprozess nicht läuft.

Beispiel für einen Mail-Worker jede Minute:

```cron
* * * * * cd /pfad/zu/fachdock && /usr/bin/php bin/fachdock mail:work >/dev/null 2>&1
```

Der erfolgreiche Lauf der Worker wird in `system_worker_status` protokolliert und im Systemstatus beziehungsweise bei der E-Mail-Konfiguration angezeigt.

## E-Mail-Warteschlange und Sofortmails

Reguläre Benachrichtigungen werden persistent in `mail_queue` gespeichert und durch `mail:work` verarbeitet. Die Batch-Größe begrenzt die Anzahl der bei einem einzelnen Worker-Aufruf verarbeiteten Einträge. `mail.max_per_hour` ist ein rollierendes 60-Minuten-Gesamtlimit für erfolgreich versandte produktive Mails.

Zeitkritische Zugangs-E-Mails – derzeit Eltern-Magic-Links und E-Mail-Bestätigungslinks – werden ebenfalls zuerst persistent in die Queue geschrieben, anschließend aber unmittelbar im auslösenden Webrequest versendet. Dadurch gehen sie nicht erst beim nächsten Cron-Lauf heraus. Schlägt der SMTP-Versand fehl, bleibt der Eintrag erhalten und wird nach dem konfigurierten Retry-Schema weiterbehandelt. Ist das globale 60-Minuten-Limit bereits vollständig ausgeschöpft, bleibt die Sofortmail ebenfalls wartend; sie wird nicht am Gesamtlimit vorbei versendet.

Sofortmails zählen vollständig gegen `mail.max_per_hour`. Damit größere Mengen normaler Nachrichten – etwa Verlängerungserinnerungen – die zeitkritischen Zugangs-E-Mails nicht blockieren, reserviert `mail.immediate_reserve_per_hour` einen Teil der Stundenkapazität. Standardmäßig sind von 50 möglichen Versandplätzen 10 zunächst für Sofortmails reserviert. Bereits innerhalb des rollierenden Fensters versandte Sofortmails verbrauchen diese Reserve, sodass die Kapazität nicht doppelt zurückgehalten wird.

Die Retry-Liste `mail.retry_minutes` enthält die Wartezeiten nach aufeinanderfolgenden Fehlversuchen. Bei `[15, 60, 360]` folgt auf den ersten Fehler ein neuer Versuch nach 15 Minuten, auf den zweiten nach 60 und auf den dritten nach 360 Minuten. Scheitert anschließend ein weiterer Versuch, wird die Mail endgültig als `failed` markiert. `mail.processing_timeout_minutes` ist ausschließlich ein Crash-Schutz für Einträge, die nach einem abgebrochenen Worker-Lauf auf `processing` stehen geblieben sind; er ist kein SMTP-Verbindungs-Timeout.

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
