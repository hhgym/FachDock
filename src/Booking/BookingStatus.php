<?php

declare(strict_types=1);

namespace FachDock\Booking;

enum BookingStatus: string
{
    case Active = 'active';
    case ExemptionReview = 'exemption_review';
    case PaymentDue = 'payment_due';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
