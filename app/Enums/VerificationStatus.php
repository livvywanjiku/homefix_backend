<?php

namespace App\Enums;

/**
 * Where a professional stands in the trust review (spec §19).
 *
 * Verification is the platform's central trust signal: customers filter on it,
 * search ranks on it, and the badge it drives is the main thing distinguishing
 * one professional from another before there are reviews.
 */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether a professional in this state may be shown to customers.
     *
     * Only Verified professionals appear in search and can be booked. Pending
     * is excluded too: exposing an unreviewed applicant would make the
     * verification queue meaningless.
     */
    public function isVisibleToCustomers(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether the professional may submit a fresh application.
     *
     * A rejected application is recoverable — the professional fixes what was
     * wrong and resubmits. A suspension is not: it is an administrative action
     * and only an administrator lifts it.
     */
    public function isResubmittable(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
    }
}
