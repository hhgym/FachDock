# FachDock 1.0.0-rc.1 – Befunde aus der Abnahme

Diese Liste sammelt die Punkte aus der manuellen Abnahme von `1.0.0-rc.1`, die vor der stabilen Veröffentlichung von `1.0.0` angepasst werden sollen.

## Erledigt

- [x] Header-Menü: Geöffnete Desktop-Dropdowns schließen sich wieder, wenn die Maus den zugehörigen Menübereich verlässt. Beim direkten Wechsel vom Button in das Dropdown verhindert eine kurze Verzögerung ein unbeabsichtigtes Schließen. Mobile Navigation und Tastaturbedienung bleiben unverändert.
- [x] Elternlogin `/parent/login`: Die Aktionen unter dem Datenschutzhinweis werden untereinander mit definiertem Abstand dargestellt, sodass Button- und Linktexte auch bei schmaler Darstellung nicht mehr überlappen.
- [x] Konfiguration nach Speichern: Stripe-, SMTP- und andere lokale Konfigurationswerte werden nach dem Speichern sofort aktuell angezeigt. Dynamische HTML- und Redirect-Antworten sind `no-store`, und nach dem atomaren Austausch der PHP-Konfigurationsdateien werden Dateistatus- und OPcache-Einträge invalidiert. Dadurch ist kein zusätzlicher manueller Seitenreload mehr erforderlich.
- [x] Schülerimport `/admin/students`: Checkbox und Beschreibung des vollständigen Imports stehen sauber nebeneinander. Die Checkbox zum Überspringen ungültiger Zeilen verwendet dieselbe konsistente Darstellung.
- [x] Lagepläne `/admin/floorplans`: Die Seite verwendet nun wie die übrigen Admin-Seiten eine maximale Inhaltsbreite von 960 px mit 16 px Mindestabstand zu den Bildschirmrändern. Der Lageplan und die Verwaltungsbereiche werden innerhalb dieser Breite einspaltig angeordnet.
- [x] Dashboard mobil: Die Aktionen „Schließfachbetrieb öffnen“ und „Systemstatus prüfen“ umbrechen sauber und werden auf schmalen Bildschirmen untereinander in voller verfügbarer Breite dargestellt.
- [x] Lageplan-Zoom: Vergrößern und Verkleinern bleibt innerhalb des vorgesehenen Scrollbereichs. Der Lageplan vergrößert seine tatsächliche Zeichenfläche, statt per Transformation über den Rand der Karte hinauszuragen.
- [x] Lageplan-Geometrie: Bereits positionierte Schrankgruppen können direkt im Lageplan verschoben und über einen Ziehpunkt unten rechts in Breite und Höhe verändert werden. Die geänderten Werte werden mit den vorhandenen Positionsfeldern synchronisiert und anschließend wie bisher gespeichert.
- [x] Lageplan-Platzierung: Noch nicht positionierte, bereits in den Stammdaten vorhandene Schrankgruppen können ausgewählt und anschließend durch Aufziehen eines Rechtecks direkt im Lageplan platziert werden. Neue strukturelle Schrankgruppen mit Bereich, Korpussen und Fächern werden weiterhin unter „Standorte“ angelegt; der Lageplan verwaltet Position und Geometrie.
- [x] Mail-Warteschlange: Die Konfigurationsseite erklärt Worker-Batch, rollierendes 60-Minuten-Limit, Retry-Abstände und Processing-Timeout eindeutig, zeigt den letzten erfolgreichen Mail-Worker-Lauf an und nennt den erforderlichen externen Cron-/systemd-Aufruf. Eltern-Magic-Links und E-Mail-Bestätigungslinks sind Sofortmails: Sie werden zuerst dauerhaft in der Queue gespeichert und anschließend bereits im auslösenden Webrequest versendet. Erfolgreiche Sofortmails zählen vollständig gegen dasselbe 60-Minuten-Gesamtlimit. Eine konfigurierbare Stundenreserve (Standard 10) verhindert, dass normale Warteschlangen-Mails die gesamte Kapazität vor zeitkritischen Sofortmails belegen; bereits versandte Sofortmails verbrauchen diese Reserve, sodass keine Kapazität doppelt zurückgehalten wird. Bei ausgeschöpftem Gesamtlimit bleibt die Sofortmail wartend, bei einem SMTP-Fehler greift das reguläre Retry-Schema.

## Offen

Weitere Befunde werden während der RC-Abnahme ergänzt.
