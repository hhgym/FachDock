# Stripe-Zahlungen

FachDock verwendet Stripe Checkout für Online-Zahlungen. Die Rückleitung des Browsers und der Stripe-Webhook ergänzen sich, haben aber unterschiedliche Aufgaben.

## Konfiguration

Für Stripe müssen mindestens folgende Werte gesetzt sein:

- `app.base_url`: kanonische öffentliche HTTPS-URL der FachDock-Installation
- `stripe.mode`: `test` oder `live`
- `stripe.test.secret_key`: geheimer API-Schlüssel des Testmodus (`sk_test_...`)
- `stripe.test.webhook_secret`: Signing Secret des Test-Webhooks (`whsec_...`)
- `stripe.live.secret_key`: geheimer API-Schlüssel des Live-Modus (`sk_live_...`)
- `stripe.live.webhook_secret`: Signing Secret des Live-Webhooks (`whsec_...`)

Die geheimen Werte gehören in `config/secrets.local.php`, zum Beispiel:

```php
<?php

return [
    'stripe' => [
        'test' => [
            'secret_key' => 'sk_test_...',
            'webhook_secret' => 'whsec_...',
        ],
        'live' => [
            'secret_key' => 'sk_live_...',
            'webhook_secret' => 'whsec_...',
        ],
    ],
];
```

Der aktive Modus wird ausschließlich über `stripe.mode` gewählt. Im Testmodus verwendet FachDock nur die Test-Credentials, im Live-Modus nur die Live-Credentials. Dadurch müssen beim Umschalten keine Secrets ausgetauscht werden.

Aus Gründen der Rückwärtskompatibilität werden die früheren Werte `stripe.secret_key` und `stripe.webhook_secret` weiterhin verwendet, solange für den aktiven Modus noch gar keine neuen Credentials eingetragen sind. Sobald einer der modusspezifischen Werte gesetzt ist, mischt FachDock keine alten Werte hinzu. Dadurch wird insbesondere verhindert, dass versehentlich ein API-Key aus einem Modus mit einem Webhook-Secret aus einem anderen Modus kombiniert wird.

Test- und Live-Modus sind bei Stripe getrennt. Ein im Live-Modus angelegter Webhook empfängt keine Testereignisse und umgekehrt.

## Webhook-Endpunkt

In Stripe muss in beiden Modi jeweils ein eigener Webhook-Endpunkt mit folgender URL angelegt werden:

```text
https://<fachdock-host>/webhooks/stripe
```

FachDock verarbeitet derzeit folgende Checkout-Ereignisse:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`

Nach dem Anlegen des jeweiligen Endpunkts muss dessen Signing Secret unter `stripe.test.webhook_secret` bzw. `stripe.live.webhook_secret` hinterlegt werden.

Ein normaler Browseraufruf von `/webhooks/stripe` verwendet `GET`. FachDock beantwortet diesen Diagnoseaufruf mit HTTP 200 und einem kurzen Hinweistext. Stripe selbst sendet Ereignisse dagegen per `POST` mit einem gültigen `Stripe-Signature`-Header.

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
- Ist das Signing Secret des Endpunkts unter dem zum Modus passenden Konfigurationsschlüssel hinterlegt?

Ein erreichter Endpunkt mit falschem Signing Secret oder einem Serverfehler würde dagegen als fehlgeschlagener Zustellversuch in Stripe erscheinen.

### Browseraufruf des Webhook-Endpunkts

Ein Aufruf von `https://<fachdock-host>/webhooks/stripe` im Browser dient nur als Erreichbarkeitstest. Er bestätigt nicht, dass das Signing Secret korrekt ist oder Stripe den Endpunkt im richtigen Modus konfiguriert hat. Dafür ist ein tatsächlicher Stripe-Webhook-Zustellversuch maßgeblich.

### Die Rückkehrseite zeigt weiterhin einen offenen Vorgang

Dann sollte geprüft werden, ob Stripe die Checkout-Session tatsächlich als `paid` meldet. Bei asynchronen Zahlarten kann `checkout.session.completed` eintreffen, bevor die Zahlung endgültig erfolgreich ist; FachDock wartet dann auf `checkout.session.async_payment_succeeded`.

### Zahlung bestätigt, Buchung aber nicht angelegt

In diesem Ausnahmefall setzt FachDock den Zahlungsvorgang auf `manual_review`. Damit bleibt sichtbar, dass Geld eingegangen ist, während die Schließfachverwaltung die Buchung prüfen muss.
