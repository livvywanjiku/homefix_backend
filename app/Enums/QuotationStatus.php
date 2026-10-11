<?php

namespace App\Enums;

/**
 * The life of a professional's price offer (spec §9).
 *
 * Deliberately separate from `ServiceRequestStatus`. The two move for different
 * reasons: a request's state describes how the *advertisement* is doing, while a
 * quotation's describes one professional's offer on it. A single request can hold
 * four quotations in four different states at once.
 */
enum QuotationStatus: string
{
    /** Submitted and awaiting the customer's decision. */
    case Pending = 'pending';

    /**
     * The customer took this one. Exactly one quotation per request can be in
     * this state — the award is serialised under a row lock in QuotationService.
     */
    case Accepted = 'accepted';

    /**
     * The customer chose somebody else, or turned this one down individually.
     *
     * Also the resting state of every other pending offer on a request the moment
     * one is accepted, which is why it carries no judgement about the offer.
     */
    case Rejected = 'rejected';

    /** Pulled by the professional before the customer decided. */
    case Withdrawn = 'withdrawn';

    /** Passed `valid_until` without a decision. */
    case Expired = 'expired';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting your decision',
            self::Accepted => 'Accepted',
            self::Rejected => 'Not selected',
            self::Withdrawn => 'Withdrawn by professional',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether the offer is still in the running.
     *
     * The one state from which both sides still have a move: the customer can
     * accept or reject it, and the professional can revise or withdraw it.
     */
    public function isLive(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the offer has come to rest. Nothing further can happen to it.
     */
    public function isFinal(): bool
    {
        return ! $this->isLive();
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
