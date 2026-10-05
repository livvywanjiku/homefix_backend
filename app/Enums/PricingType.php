<?php

namespace App\Enums;

/**
 * How a professional prices a service.
 *
 * The three cases mirror how trades actually quote in this market: a fixed
 * price for a well-defined job, an hourly rate for open-ended work, and "on
 * inspection" for anything that cannot be priced without seeing it — which is
 * ultimately quoted through the quotation flow in spec §9.
 */
enum PricingType: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';
    case Quote = 'quote';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed price',
            self::Hourly => 'Hourly rate',
            self::Quote => 'Quote on inspection',
        };
    }

    /**
     * Whether this pricing type carries a price range.
     *
     * A "quote on inspection" service deliberately shows no figure, so the
     * price columns are ignored for it rather than stored as zero — zero would
     * render as "KSh 0" and read as free.
     */
    public function hasPrice(): bool
    {
        return $this !== self::Quote;
    }

    /**
     * The suffix shown after a price, e.g. "KSh 1,500/hr".
     */
    public function priceSuffix(): string
    {
        return match ($this) {
            self::Hourly => '/hr',
            default => '',
        };
    }
}
