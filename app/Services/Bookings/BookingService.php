<?php

namespace App\Services\Bookings;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only thing that moves a booking (spec §10).
 *
 * `BookingStatus` declares which moves are legal; this enforces them, stamps the
 * timestamps each state implies, writes the history row and fires the event. No
 * controller, job or console command writes `bookings.status` — a status write
 * that skips this method is a bug, because it is the single place where the
 * state machine, the audit trail and the notifications are guaranteed to agree.
 *
 * "Prevent invalid status transitions" is a security requirement, not a UI one:
 * a client that posts `completed` against a booking still sitting at `pending`
 * is refused here with a 422, whatever the frontend believed it was doing.
 */
class BookingService
{
    /**
     * Move a booking to another state.
     *
     * @throws ValidationException when the move is not permitted
     */
    public function transition(
        Booking $booking,
        BookingStatus $to,
        ?User $actor = null,
        ?string $note = null,
    ): Booking {
        $result = DB::transaction(function () use ($booking, $to, $actor, $note): array {
            /*
             * Re-read under the lock rather than trusting the copy the caller
             * holds. Two requests advancing the same booking at once would
             * otherwise both pass the check below and both write.
             *
             * `lockForUpdate` is a no-op on the sqlite database the tests use and
             * a real row lock on Postgres; on both, this read inside the
             * transaction is what makes the guard hold.
             */
            $current = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $current->status;

            /*
             * A repeat of the state it is already in is a no-op, not an error.
             * Double-tapping "Start work", or a retried request, should not
             * produce a 422 or a second history row.
             */
            if ($from === $to) {
                return [$current, null];
            }

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'A booking that is %s cannot be moved to %s.',
                        strtolower($from->label()),
                        strtolower($to->label()),
                    ),
                ]);
            }

            $current->forceFill($this->stamps($to, $actor) + ['status' => $to])->save();

            $history = BookingStatusHistory::create([
                'booking_id' => $current->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'changed_by' => $actor?->id,
                'note' => $note,
            ]);

            if ($to === BookingStatus::Completed) {
                $this->creditProfessional($current);
            }

            return [$current, [$from, $history]];
        });

        [$booking, $change] = $result;

        // Dispatched outside the transaction: a listener must never observe a
        // move that was subsequently rolled back.
        if ($change !== null) {
            [$from] = $change;

            BookingStatusChanged::dispatch($booking->refresh(), $from, $to, $actor, $note);
        }

        return $booking;
    }

    /**
     * The timestamps a state implies.
     *
     * Derived from the target state rather than passed in, so `completed_at` is
     * set by the move to `completed` and cannot be forged by a caller, and so
     * two entry points into the same state stamp it identically.
     *
     * @return array<string, mixed>
     */
    private function stamps(BookingStatus $to, ?User $actor): array
    {
        return match ($to) {
            BookingStatus::InProgress => ['started_at' => now()],

            BookingStatus::Completed => ['completed_at' => now()],

            BookingStatus::Cancelled => [
                'cancelled_at' => now(),
                // Recorded so a cancellation can be attributed. Null when the
                // platform cancelled it on its own — an expiry, say — which is
                // more honest than naming whoever happened to be signed in.
                'cancelled_by' => $actor?->id,
            ],

            default => [],
        };
    }

    /**
     * Bump the professional's completed-job count.
     *
     * Done inside the same transaction as the move to `completed`, so the
     * denormalised counter that ranks professionals can never disagree with the
     * bookings that produced it. Incremented rather than recounted: this is the
     * one place the state changes, so it is the one place the counter must.
     */
    private function creditProfessional(Booking $booking): void
    {
        DB::table('professional_profiles')
            ->where('user_id', $booking->professional_id)
            ->increment('completed_jobs_count');
    }
}
