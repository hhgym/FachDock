<?php

declare(strict_types=1);

namespace FachDock\Payment;

enum PaymentStatus: string
{
    case Creating = 'creating';
    case CheckoutOpen = 'checkout_open';
    case ProcessingPaid = 'processing_paid';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case ManualReview = 'manual_review';
}
