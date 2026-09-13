# Stripe-Zahlungen

FachDock verwendet Stripe Checkout für Online-Zahlungen. Browser-Rückleitung und Stripe-Webhook ergänzen sich: Der Webhook bleibt der maßgebliche asynchrone Kanal, während FachDock bei der Browser-Rückkehr den Stripe-Status zusätzlich serverseitig prüfen kann.

## Konfiguration

Für Stripe müssen folgende Werte gesetzt sein:

- `app.base_url`: kanonische öffentliche HTTPS-URL der FachDock-Installation
- `stripe.mode`: `test` oder `live`
- `stripe.test.secret_key`: Test-API-Schlüssel (`sk_test_...`)
- `stripe.test.webhook_secret`: Signing Secret des Test-Webhooks (`whsec_...`)
- `stripe.live.secret_key`: Live-API-Schlüssel (`sk_live_...`)
- `stripe.live.webhook_secret`: Signing Secret des Live-Webhooks (`whsec_...`)

FachDock speichert Test- und Live-Zugangsdaten parallel. `stripe.mode` bestimmt ausschließlich, welches Schlüsselpaar aktiv verwendet wird. Die Zugangsdaten des anderen Modus bleiben gespeichert.

Bestehende Installationen mit den früheren Schlüsseln `stripe.secret_key` und `stripe.webhook_secret` bleiben kompatibel, solange für den ausgewählten Modus noch kein modusspezifisches Schlüsselpaar gespeichert wurde. Sobald für einen Modus neue Werte existieren, werden alte und neue Werte nicht gemischt.

Über **Konfiguration → Stripe** werden neue Secrets automatisch unter dem aktuell ausgewählten Modus gespeichert.

## Webhook-Endpunkt

In Stripe muss jeweils im Test- und im Live-Modus ein eigener Webhook-Endpunkt mit derselben öffentlichen URL angelegt werden:

```text
https://<fachdock-host>/webhooks/stripe
```

FachDock verarbeitet derzeit folgende Checkout-Ereignisse:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`

Nach dem Anlegen des Endpunkts muss das jeweilige Signing Secret dem passenden Modus in FachDock zugeordnet werden.

### Erreichbarkeit prüfen

Ein Browseraufruf von

```text
https://<fachdock-host>/webhooks/stripe
```

sendet einen GET-Request. FachDock beantwortet diesen mit HTTP 200 und einem Diagnosehinweis. Stripe selbst sendet die eigentlichen Ereignisse weiterhin per POST mit einem gültigen `Stripe-Signature`-Header.

## Zahlungsablauf

1. FachDock reserviert das Schließfach und legt einen lokalen Zahlungsvorgang an.
2. Die Eltern werden zu Stripe Checkout weitergeleitet.
3. Nach Abschluss leitet Stripe den Browser mit `payment_id` und `session_id` zu FachDock zurück.
4. FachDock gleicht diese Checkout-Session serverseitig mit Stripe ab. Meldet Stripe die Session bereits als bezahlt, kann die Reservierung sofort in eine Buchung umgewandelt werden, auch wenn der Webhook noch nicht angekommen ist.
5. Zusätzlich verarbeitet FachDock den Stripe-Webhook. Dieser bleibt insbesondere für asynchrone Zahlarten, verspätete Ereignisse und Fälle ohne Browser-Rückleitung erforderlich.

Der Browser-Abgleich ist daher ein Sicherheitsnetz und kein Ersatz für den Webhook.

## Anzeige im Elternportal

Solange eine Reservierungszahlung noch nicht endgültig in eine Buchung umgewandelt wurde, erscheint sie im Elternportal unter **Aktuelle Zahlungsvorgänge**. Dabei werden insbesondere folgende Zustände sichtbar gemacht:

- Zahlung wird bearbeitet
- Zahlung wird verbucht
- Zahlung eingegangen – Prüfung erforderlich

Damit verschwindet ein bezahltes bzw. noch in Verarbeitung befindliches Schließfach nicht einfach aus der Übersicht, nur weil noch keine endgültige Buchung existiert.

## Fehlersuche

### In Stripe gibt es gar keinen Webhook-Verlauf

Wenn im Stripe-Dashboard für den FachDock-Endpunkt überhaupt kein Zustellversuch erscheint, prüfen:

- Ist der Endpunkt im aktuell verwendeten Test- bzw. Live-Modus angelegt?
- Lautet die URL exakt `/webhooks/stripe` unter der öffentlichen `app.base_url`?
- Sind die oben genannten Checkout-Ereignisse für den Endpunkt ausgewählt?
- Gehört das hinterlegte `whsec_...` zum richtigen Modus?

Ein erreichter Endpunkt mit falschem Signing Secret oder einem Serverfehler würde dagegen als fehlgeschlagener Zustellversuch in Stripe erscheinen.

### Die Rückkehrseite zeigt weiterhin einen offenen Vorgang

Dann sollte geprüft werden, ob Stripe die Checkout-Session tatsächlich als `paid` meldet. Bei asynchronen Zahlarten kann `checkout.session.completed` eintreffen, bevor die Zahlung endgültig erfolgreich ist; FachDock wartet dann auf `checkout.session.async_payment_succeeded`.

### Zahlung bestätigt, Buchung aber nicht angelegt

Kann eine bestätigte Zahlung aus fachlichen oder technischen Gründen nicht in eine Buchung umgewandelt werden, setzt FachDock den Zahlungsvorgang auf `manual_review`. Damit bleibt sichtbar, dass Geld eingegangen ist, während die Schließfachverwaltung die Buchung prüfen muss.
