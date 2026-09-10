# FachDock 1.0.0-rc.1 – Befunde aus der Abnahme

Diese Liste sammelt die Punkte aus der manuellen Abnahme von `1.0.0-rc.1`, die vor der stabilen Veröffentlichung von `1.0.0` angepasst werden sollen.

## Erledigt

- [x] Header-Menü: Geöffnete Desktop-Dropdowns schließen sich wieder, wenn die Maus den zugehörigen Menübereich verlässt. Beim direkten Wechsel vom Button in das Dropdown verhindert eine kurze Verzögerung ein unbeabsichtigtes Schließen. Mobile Navigation und Tastaturbedienung bleiben unverändert.
- [x] Elternlogin `/parent/login`: Die Aktionen unter dem Datenschutzhinweis werden untereinander mit definiertem Abstand dargestellt, sodass Button- und Linktexte auch bei schmaler Darstellung nicht mehr überlappen.
- [x] Konfiguration nach Speichern: Stripe-, SMTP- und andere lokale Konfigurationswerte werden nach dem Speichern sofort aktuell angezeigt. Dynamische HTML- und Redirect-Antworten sind `no-store`, und nach dem atomaren Austausch der PHP-Konfigurationsdateien werden Dateistatus- und OPcache-Einträge invalidiert. Dadurch ist kein zusätzlicher manueller Seitenreload mehr erforderlich.

## Offen

Weitere Befunde werden während der RC-Abnahme ergänzt.
