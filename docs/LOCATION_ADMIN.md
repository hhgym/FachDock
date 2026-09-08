# Standort- und Schließfachverwaltung

Die physische Struktur von FachDock folgt verbindlich der Hierarchie

`Gebäude → Etage → Bereich → Schrankgruppe → Korpus → Schließfach`.

## Nummerierung

Schrankgruppen erhalten globale Codes `A` bis `Z`, danach `AA`, `AB` usw. Korpusse werden innerhalb einer Gruppe von links nach rechts nummeriert, Schließfächer innerhalb eines Korpus von oben nach unten.

Beispiele:

- Kurzbezeichnung: `A-07-2`
- Langbezeichnung: `1OG-78-A-07-2`

Die Kurzbezeichnung ist die dauerhafte Identität eines Schließfachs. Die Langbezeichnung wird aus der aktuellen Standortstruktur abgeleitet und kann sich bei geänderten Standortbezeichnungen ändern.

## Strukturänderungen

Eine Schrankgruppe darf vollständig neu aufgebaut oder gelöscht werden, solange sie noch nie historisch verwendet wurde. Sobald ein späteres Modul eine erste historische Referenz erzeugt, muss `CabinetGroupService::lockStructureForHistoricalUse()` aufgerufen werden. Danach ist die Korpusreihenfolge dauerhaft gesperrt.

Metadaten wie Name, Standortzuordnung und Aktivstatus bleiben bearbeitbar. Historische Buchungen speichern zusätzlich Standort-Snapshots und bleiben dadurch unabhängig von späteren Umbenennungen rekonstruierbar.

## Korpustypen

Korpustypen definieren die Anzahl der Fächer und die standardmäßig barrierearmen Positionen. Die Fachanzahl kann nur geändert werden, solange der Typ noch in keinem Korpus verwendet wird. Bezeichnungen und barrierearme Positionen können später angepasst werden; bestehende Schließfächer werden hinsichtlich der barrierearmen Kennzeichnung synchronisiert.

## Betriebszustand

Der physische Zustand eines Schließfachs ist von seiner Belegung getrennt. Unterstützte Betriebszustände sind:

- `operational`
- `blocked`
- `defective`
- `out_of_service`

Zusätzlich besitzt jedes Schließfach die unabhängigen Flags `active` und `bookable`. Die spätere Buchungslogik kombiniert diese Merkmale mit Belegung, Schuljahr und Zuteilungsregeln.
