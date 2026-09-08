<?php

declare(strict_types=1);

namespace FachDock\Booking;

enum ReservationStatus: string
{
    case Active = 'active';
    case PaymentRunning = 'payment_running';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
