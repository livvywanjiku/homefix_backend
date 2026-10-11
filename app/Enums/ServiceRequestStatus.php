<?php

namespace App\Enums;

/**
 * The life of a customer's job posting (spec §8).
 *
 * A request is the customer's advertisement of work. It is answered with
 * quotations rather than being booked directly, so its states describe how the
 * *advertisement* is doing, not how a piece of work is progressing — that is
 * `BookingStatus`'s job once a quotation is accepted.
 */
enum ServiceRequestStatus: string
{
    case Open = 'open';
    case Quoted = 'quoted';

    /**
     * A quotation was accepted and a booking exists. The request stops
     * attracting new quotations at this point.
     */
    case Awarded = 'awarded';

    /** Withdrawn by the customer without a booking, or completed and archived. */
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Passed `expires_at` without being awarded. Distinct from `Cancelled`:
     * nobody decided anything, so it is not held against the customer.
     */
    case Expired = 'expired';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Quoted => 'Quotes received',
            self::Awarded => 'Awarded',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether the request is still on the public board.
     *
     * `Quoted` stays listed on purpose: a customer with one quotation is still
     * usually waiting on a second opinion, and hiding the request would take
     * away their only chance of getting one.
     */
    public function isListed(): bool
    {
        return $this === self::Open || $this === self::Quoted;
    }

    /**
     * Whether a professional may still submit a quotation for it.
     */
    public function acceptsQuotations(): bool
    {
        return $this->isListed();
    }

    /**
     * Whether the customer may still edit the request's details.
     *
     * Once a professional has quoted, the terms they priced cannot be rewritten
     * underneath them — that would let a customer bait a quote and change the
     * job. Cancelling and reposting is the honest way to change scope.
     */
    public function isEditable(): bool
    {
        return $this === self::Open;
    }

    /**
     * Whether the request has come to rest. Nothing further can happen to it,
     * so every customer-initiated action is refused.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Awarded, self::Closed, self::Cancelled, self::Expired => true,
            self::Open, self::Quoted => false,
        };
    }

    /**
     * Whether the customer may cancel the request.
     */
    public function isCancellable(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * Every status, for validation rules and admin filters.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
