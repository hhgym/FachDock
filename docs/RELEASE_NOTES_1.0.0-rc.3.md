# FachDock 1.0.0-rc.3

`1.0.0-rc.3` ist der dritte Release Candidate für FachDock 1.0 und wird als GitHub-**Prerelease** veröffentlicht. Er baut auf `1.0.0-rc.2` auf und bündelt die seitdem entwickelten Funktionen für Updates, OpenID Connect, Benutzerverwaltung und Datenschutz-Lifecycle.

## Neu seit 1.0.0-rc.2

### Updatekanäle

- Der integrierte Updater unterscheidet jetzt **Stable**, **RC** und optional **Develop**.
- Stable bleibt der sichere Standard und berücksichtigt nur stabile GitHub-Releases.
- Der RC-Kanal kann gezielt freigeschaltet werden und berücksichtigt stabile Releases sowie veröffentlichte `-rc.N`-Versionen.
- Der Develop-Kanal kann separat freigeschaltet werden und verwendet rolling Pakete aus `develop-build`, die über den Commit-SHA identifiziert werden.
- FachDock führt dabei keinen automatischen Downgrade aus.

### OpenID Connect

- Die Konfiguration verwendet providerneutrale OpenID-Connect-Bezeichnungen; die Beschriftung des Login-Buttons ist konfigurierbar.
- Administratoren können einen echten **OIDC-Testlogin mit PKCE** durchführen, ohne den produktiven OIDC-Zugang vorher aktivieren zu müssen.
- Nach dem Testlogin zeigt FachDock die tatsächlich gelieferten UserInfo-Claims an.
- Der Testlogin legt keine dauerhafte Identität, keine FachDock-Session und keine Schülerzuordnung an; Access- und ID-Tokens werden weder angezeigt noch dauerhaft gespeichert.
- Die automatische Schülerzuordnung ist über drei Bausteine konfigurierbar: akzeptierte Schülerrollen, OIDC-Claim und FachDock-Zielfeld.
- Als Zielfeld stehen insbesondere E-Mail und Matrikelnummer zur Verfügung.
- Für IServ kann dadurch beispielsweise `untis_username` auf die Matrikelnummer abgebildet werden. Wird dieser Claim benötigt, kann der Scope `iserv:untis` ergänzt werden. Der tatsächliche übertragene Wert sollte vor Einsatz über den Testlogin geprüft werden.
- Die bestehende Konfiguration bleibt soweit möglich rückwärtskompatibel.
- Ein OpenID-Connect-Zugang für Eltern ist nicht Bestandteil dieses RC; das Elternportal verwendet weiterhin Magic Links.

### Login, Dashboard und lokale Benutzer

- Login- und Dashboarddarstellung wurden für die providerneutrale Anmeldung und responsive Nutzung weiter vereinheitlicht.
- Lokale Mitarbeiterkonten besitzen einen klareren Lebenszyklus mit Deaktivierung und Reaktivierung.
- Die Benutzerverwaltung trennt lokale Mitarbeiterkonten von Schüler- und Elternkonten.

### Schüler- und Elternkonten

- Schülerstammdaten und Benutzerkonten werden getrennt verwaltet. Aus den Schülerdaten kann direkt zum zugehörigen Benutzerkonto gewechselt werden, sofern eines vorhanden ist.
- Wird ein Schüler im Stammdatenimport inaktiv, beginnt ein konfigurierbarer Nachlauf. Standardmäßig wird das Konto nach **30 Tagen deaktiviert**.
- Die personenbezogenen Schülerdaten werden standardmäßig nach **365 Tagen anonymisiert**. Deaktivierungs- und Anonymisierungsfrist sind getrennt konfigurierbar.
- Während des Nachlaufs kann ein bestehender Schülerzugang weiterhin verwendet und auch eine automatische OpenID-Connect-Zuordnung hergestellt werden.
- Kehrt ein Schüler zurück, wird eine automatisch durch den Lifecycle gesetzte Sperre aufgehoben. Eine manuell gesetzte Sperre bleibt bestehen.
- Elternkonten bleiben vom automatischen Fristlauf ausgenommen, solange mindestens ein aktives Kind verknüpft ist.
- Sobald kein aktives Kind mehr verknüpft ist, beginnt die Elternfrist. Standardmäßig erfolgen Deaktivierung und Anonymisierung nach **1095 Tagen (3 Jahren)**.
- Der Fristbeginn berücksichtigt das jüngste relevante Ereignis aus Inaktivierung eines Kindes und Beendigung einer Eltern-Kind-Verknüpfung.
- Schüler- und Elternkonten können administrativ manuell deaktiviert und reaktiviert werden.
- Eine sofortige Anonymisierung ist nur nach exakter Texteingabe `ANONYMISIEREN <ID>` möglich.
- Der bestehende tägliche `privacy:tick` verarbeitet auch den neuen Account-Lifecycle; ein zusätzlicher Cronjob ist nicht erforderlich.

## Kompatibilität und Migration

- Bestehende Installationen werden über die normalen Datenbankmigrationen auf die neuen Lifecycle-Felder und Standardfristen aktualisiert.
- Bereits anonymisierte Datensätze werden beim Upgrade in den neuen Lifecycle überführt.
- Vorhandene Schülerimporte starten die Inaktivitätsfrist nur beim tatsächlichen Übergang von aktiv zu inaktiv; weitere Importe verschieben sie nicht.
- Die bisherigen Buchungs-, Zahlungs-, Mail-, Lageplan-, Backup- und Restorefunktionen bleiben Bestandteil des RC.

## Abnahme

Für `1.0.0-rc.3` sollen zusätzlich zu den bisherigen RC-Tests besonders geprüft werden:

1. Upgrade einer bestehenden `1.0.0-rc.2`-Testinstallation sowie Neuinstallation aus dem RC3-ZIP.
2. Stable-/RC-/Develop-Updatekanäle und korrekte Auswahl ohne Downgrade.
3. OpenID-Connect-Discovery und echter Testlogin mit Kontrolle der übertragenen Claims.
4. Automatische Schülerzuordnung über Rolle, Claim und Zielfeld in der realen OIDC-/IServ-Umgebung.
5. Schüler-Nachlauf, automatische Deaktivierung, Rückkehr und Anonymisierung.
6. Schutz einer manuellen Schülersperre vor automatischer Reaktivierung durch einen Import.
7. Elternfrist nach Wegfall des letzten aktiven Kindes, auch wenn die letzte Verknüpfung administrativ beendet wurde.
8. Manuelle Deaktivierung/Reaktivierung und die bestätigungspflichtige Sofortanonymisierung.
9. Getrennte Schülerdaten- und Benutzerkontenansichten auf Desktop und Mobilgerät.

Die vollständige Abnahmeliste steht in `docs/RELEASE_CANDIDATE.md`.

## Release-Artefakte

Der automatisierte Releaseworkflow erzeugt und prüft:

- `FachDock-1.0.0-rc.3.zip`
- `FachDock-1.0.0-rc.3.zip.sha256`

`1.0.0-rc.3` ist weiterhin **nicht** die stabile Version `1.0.0`.
