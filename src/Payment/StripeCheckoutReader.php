<?php

declare(strict_types=1);

namespace FachDock\Payment;

interface StripeCheckoutReader
{
    public function retrieveCheckoutState(string $sessionId): StripeCheckoutState;
}
