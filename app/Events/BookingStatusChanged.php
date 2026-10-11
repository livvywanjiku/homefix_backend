<?php

namespace App\Events;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking moved from one state to another (spec §10).
 *
 * Dispatched after the transaction commits, so a listener never sees a move
 * that was rolled back. This is the hook the notification slice attaches to: a
 * status change is the single event both sides care about, and there is exactly
 * one place it can happen.
 */
class BookingStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingStatus $from,
        public readonly BookingStatus $to,
        public readonly ?User $actor = null,
        public readonly ?string $note = null,
    ) {}
}
