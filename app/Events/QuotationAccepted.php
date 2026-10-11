<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The customer took a professional's offer (spec §9–§10).
 *
 * Both models travel together because the two facts are one event: the offer was
 * accepted, and a booking now exists. A listener that notified the professional
 * about only the first would leave them with no reference for the work, and one
 * that announced the booking alone would not say which offer it settled.
 */
class QuotationAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Quotation $quotation,
        public Booking $booking,
    ) {}
}
