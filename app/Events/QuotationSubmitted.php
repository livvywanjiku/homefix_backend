<?php

namespace App\Events;

use App\Models\Quotation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A professional has priced a customer's job (spec §9).
 *
 * Fired after the quotation and its lines are committed, so a listener that
 * notifies the customer cannot announce an offer the database later rolled back.
 * It carries the quotation as it stood at submission; the notification flow reads
 * the lines through the relation rather than expecting them on the event.
 */
class QuotationSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Quotation $quotation) {}
}
