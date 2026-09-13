# Stripe-Zahlungen

FachDock verwendet Stripe Checkout für Online-Zahlungen. Die Rückleitung des Browsers und der Stripe-Webhook ergänzen sich, haben aber unterschiedliche Aufgaben.

## Konfiguration

Für Stripe müssen mindestens folgende Werte gesetzt sein:

- `app.base_url`: kanonische öffentliche HTTPS-URL der FachDock-Installation
- `stripe.mode`: `test` oder `live`
- `stripe.secret_key`: zum Modus passender geheimer API-Schlüssel (`sk_test_...` bzw. `sk_live_...`)
- `stripe.webhook_secret`: Signing Secret des für FachDock angelegten Webhook-Endpunkts (`whsec_...`)

Test- und Live-Modus sind bei Stripe getrennt. Ein im Live-Modus angelegter Webhook empfängt keine Testereignisse und umgekehrt.

## Webhook-Endpunkt

In Stripe muss im passenden Modus ein Webhook-Endpunkt mit folgender URL angelegt werden:

```text
https://<fachdock-host>/webhooks/stripe
```

FachDock verarbeitet derzeit folgende Checkout-Ereignisse:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`

Nach dem Anlegen des Endpunkts muss dessen Signing Secret als `stripe.webhook_secret` in FachDock hinterlegt werden.

## Zahlungsablauf

1. FachDock reserviert das Schließfach und legt einen lokalen Zahlungsvorgang an.
2. Die Eltern werden zu Stripe Checkout weitergeleitet.
3. Nach Abschluss leitet Stripe den Browser mit `payment_id` und `session_id` zu FachDock zurück.
4. FachDock gleicht die zurückgegebene Checkout-Session serverseitig mit Stripe ab. Ist sie bereits als bezahlt bestätigt, kann FachDock die Reservierung sofort in eine Buchung umwandeln.
5. Zusätzlich verarbeitet FachDock den Stripe-Webhook. Dieser bleibt für asynchrone Zahlarten, verspätete Ereignisse sowie Fälle ohne Browser-Rückleitung erforderlich.

Der Abgleich bei der Browser-Rückkehr ist daher ein Sicherheitsnetz und ersetzt die Webhook-Konfiguration nicht.

## Fehlersuche

### In Stripe gibt es gar keinen Webhook-Verlauf

Wenn im Stripe-Dashboard für den FachDock-Endpunkt überhaupt kein Zustellversuch erscheint, hat Stripe den Endpunkt in diesem Modus in der Regel nicht als Empfänger des Ereignisses konfiguriert. Prüfen:

- Ist der Endpunkt im aktuell verwendeten Test- bzw. Live-Modus angelegt?
- Lautet die URL exakt `/webhooks/stripe` unter der öffentlichen `app.base_url`?
- Sind die oben genannten Checkout-Ereignisse für den Endpunkt ausgewählt?

Ein erreichter Endpunkt mit falschem Signing Secret oder einem Serverfehler würde dagegen als fehlgeschlagener Zustellversuch in Stripe erscheinen.

### Die Rückkehrseite zeigt weiterhin einen offenen Vorgang

Dann sollte geprüft werden, ob Stripe die Checkout-Session tatsächlich als `paid` meldet. Bei asynchronen Zahlarten kann `checkout.session.completed` eintreffen, bevor die Zahlung endgültig erfolgreich ist; FachDock wartet dann auf `checkout.session.async_payment_succeeded`.

### Zahlung bestätigt, Buchung aber nicht angelegt

In diesem Ausnahmefall setzt FachDock den Zahlungsvorgang auf `manual_review`. Damit bleibt sichtbar, dass Geld eingegangen ist, während die Schließfachverwaltung die Buchung prüfen muss.
