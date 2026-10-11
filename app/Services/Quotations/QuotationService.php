<?php

namespace App\Services\Quotations;

use App\Enums\QuotationStatus;
use App\Events\QuotationAccepted;
use App\Models\Booking;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The one flow in the quotation feature that outgrows a controller (spec §9).
 *
 * Awarding a job is four writes that have to be one: the chosen offer is
 * accepted, its siblings are turned down, the request is taken off the board, and
 * a booking comes into existence. Any of them landing without the others leaves
 * the marketplace in a state that lies to somebody — a customer with two accepted
 * quotations, or a professional told they won a job with no booking to show for
 * it. It lives here rather than in a controller so that the boundary is a
 * transaction rather than a request.
 */
class QuotationService
{
    /**
     * Awards the job to one offer.
     *
     * @return array{quotation: Quotation, booking: Booking}
     */
    public function accept(Quotation $quotation): array
    {
        [$accepted, $booking] = DB::transaction(function () use ($quotation): array {
            /*
             * The job is the contention point, so the job is what gets locked.
             * Two tabs accepting two different offers at the same moment would
             * otherwise both pass `isAcceptable()` and both write — the customer
             * would end up with two accepted quotations and two bookings, and no
             * way to tell which professional was actually coming.
             *
             * Serialising on the request means the second one reads the first
             * one's award and is refused. On SQLite — which the test suite runs
             * on — `lockForUpdate` compiles to nothing, so the guard that
             * actually holds there is the re-check below; on PostgreSQL, which is
             * what production runs, the lock is real.
             */
            $serviceRequest = ServiceRequest::query()
                ->whereKey($quotation->service_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Quotation::query()
                ->whereKey($quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // The locked rows are the authority. The instances that arrived here
            // were read before the transaction opened and may be stale.
            $locked->setRelation('serviceRequest', $serviceRequest);

            /*
             * A refusal, not a silent no-op. The alternative — returning the
             * quotation unchanged — would let a customer believe they had
             * accepted an offer that was already dead, and the client would have
             * no way to tell.
             */
            if (! $locked->isAcceptable()) {
                abort(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'That quotation can no longer be accepted. The job may already have been awarded, cancelled or expired.',
                );
            }

            /*
             * Every other live offer on this job is turned down in the same
             * breath, before the award is recorded. Doing it here rather than at
             * leisure is what keeps the customer's comparison table honest:
             * nobody is left holding a live offer on work that has been given
             * away, and no professional is left waiting on a decision that has
             * already been made.
             *
             * A bulk update rather than a loop over models, because these rows
             * are being settled identically and none of them should fire the
             * per-model events that belong to a single decision.
             */
            $now = now();

            Quotation::query()
                ->where('service_request_id', $serviceRequest->id)
                ->whereKeyNot($locked->id)
                ->where('status', QuotationStatus::Pending->value)
                ->update([
                    'status' => QuotationStatus::Rejected->value,
                    'rejected_at' => $now,
                    'updated_at' => $now,
                ]);

            $locked->markAccepted();

            $serviceRequest->markAwarded();

            $booking = Booking::fromQuotation($serviceRequest, $locked);

            $locked->setRelation('booking', $booking);

            return [$locked, $booking];
        });

        /*
         * Announced after the commit, never inside it. A listener that notifies
         * two people should not be able to hold a row lock open while it does, and
         * should certainly not be able to roll back an award by failing.
         */
        QuotationAccepted::dispatch($accepted, $booking);

        return ['quotation' => $accepted, 'booking' => $booking];
    }
}
