<?php

namespace App\Console\Commands;

use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Console\Command;

/**
 * Retires job postings that have run out of time.
 *
 * A request carries an `expires_at` deadline, and until this runs the deadline
 * is only a derived fact — the API already treats a lapsed request as
 * unquotable and uneditable via `hasExpired()`. This sweep makes it a stored
 * one, so the status a customer reads on their list agrees with the behaviour
 * they get, and the board's "listed" query can stop carrying the deadline test.
 *
 * Only `open` and `quoted` requests are swept: a request already awarded,
 * closed or cancelled has reached a terminal state and must keep it.
 */
class ExpireServiceRequests extends Command
{
    protected $signature = 'service-requests:expire';

    protected $description = 'Mark lapsed service requests as expired';

    public function handle(): int
    {
        $expired = 0;

        ServiceRequest::query()
            ->whereIn('status', [
                ServiceRequestStatus::Open->value,
                ServiceRequestStatus::Quoted->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($requests) use (&$expired): void {
                foreach ($requests as $request) {
                    // The named transition rather than a bulk update: a status
                    // change here is the same event the rest of the app reacts
                    // to, and a mass `update()` would bypass anything that
                    // later hangs off it.
                    $request->markExpired();
                    $expired++;
                }
            });

        $this->info($expired === 0
            ? 'No service requests had lapsed.'
            : "Expired {$expired} service request(s).");

        return self::SUCCESS;
    }
}
