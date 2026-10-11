<?php

namespace App\Console\Commands;

use App\Models\Quotation;
use Illuminate\Console\Command;

/**
 * Retires price offers whose shelf life has run out.
 *
 * Like the service-request sweep, this turns a derived fact into a stored one.
 * `Quotation::hasExpired()` already refuses to accept a lapsed offer, but a
 * professional reading their own list would otherwise see a fortnight-old price
 * still labelled "awaiting your decision" — and the customer would see a live
 * offer on a comparison table that no longer works.
 *
 * Only live quotations are swept. An accepted, rejected or withdrawn offer keeps
 * the state it was given: those are decisions, and a decision does not expire.
 */
class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Mark lapsed quotations as expired';

    public function handle(): int
    {
        $expired = 0;

        Quotation::query()
            ->live()
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($quotations) use (&$expired): void {
                foreach ($quotations as $quotation) {
                    // The named transition rather than a bulk update, so that
                    // anything later attached to an expiry — a notification to
                    // the professional, an entry in the customer's timeline —
                    // sees each one as the individual event it is.
                    $quotation->markExpired();
                    $expired++;
                }
            });

        $this->info($expired === 0
            ? 'No quotations had lapsed.'
            : "Expired {$expired} quotation(s).");

        return self::SUCCESS;
    }
}
