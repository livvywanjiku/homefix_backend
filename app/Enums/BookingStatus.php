<?php

namespace App\Enums;

/**
 * The nine controlled states of a piece of agreed work (spec §10).
 *
 * The transitions between them are declared here rather than scattered across
 * controllers, so the state machine has exactly one definition. Nothing writes a
 * booking's status directly: every change goes through BookingService::transition(),
 * which consults `allowedTransitions()` and refuses anything not on the list.
 * A status write that skips that guard is a bug, not a shortcut.
 */
enum BookingStatus: string
{
    /** Agreed in principle, not yet accepted by the professional. */
    case Pending = 'pending';

    /**
     * The professional has responded to a direct booking request with a price.
     *
     * Distinct from a `Quotation` on a service request: this is the lighter path
     * where a customer books a named professional straight from their profile
     * rather than advertising the job on the board.
     */
    case Quoted = 'quoted';

    /** Both sides have agreed. This is where accepting a quotation lands. */
    case Accepted = 'accepted';

    /** A date and a time window have been set. */
    case Scheduled = 'scheduled';

    /** The professional has set off. */
    case OnTheWay = 'on_the_way';

    /** Work has started on site. */
    case InProgress = 'in_progress';

    /** The work is done. The customer may now review it. */
    case Completed = 'completed';

    /** Called off before completion, by either side. */
    case Cancelled = 'cancelled';

    /**
     * Something went wrong and an administrator is arbitrating.
     *
     * Reachable from `completed` as well as from the working states: a fault
     * discovered after the professional has left is still a fault.
     */
    case Disputed = 'disputed';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting response',
            self::Quoted => 'Price sent',
            self::Accepted => 'Accepted',
            self::Scheduled => 'Scheduled',
            self::OnTheWay => 'On the way',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Disputed',
        };
    }

    /**
     * The states this one may move to.
     *
     * Note what is absent. Nothing returns to an earlier working state — a job
     * that has started cannot be un-started — and nothing at all follows
     * `Cancelled`, which is the one terminal state. A wrongly cancelled booking
     * is a commercial conversation, not a status edit.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            /*
             * A customer booking a named professional: the professional either
             * takes it, prices it, or turns it down.
             */
            self::Pending => [self::Accepted, self::Quoted, self::Cancelled],

            // A price was sent; the customer accepts it or walks away.
            self::Quoted => [self::Accepted, self::Cancelled],

            // Agreed, so the next real event is a date — or the work simply
            // starts, for a small job that never needed scheduling.
            self::Accepted => [self::Scheduled, self::InProgress, self::Cancelled, self::Disputed],

            self::Scheduled => [self::OnTheWay, self::InProgress, self::Cancelled, self::Disputed],

            self::OnTheWay => [self::InProgress, self::Cancelled, self::Disputed],

            // In progress cannot be cancelled: somebody is standing in the
            // customer's kitchen. A disagreement at this point is a dispute.
            self::InProgress => [self::Completed, self::Disputed],

            // A completed job can still be disputed; it cannot be cancelled
            // retrospectively, which would erase the work that was done.
            self::Completed => [self::Disputed],

            // An administrator resolves a dispute one way or the other.
            self::Disputed => [self::Completed, self::Cancelled],

            self::Cancelled => [],
        };
    }

    /**
     * Whether a move from here to `$to` is permitted.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether the booking has come to rest and nothing further may happen.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the work is finished, whether or not it went well.
     *
     * The test for "may this be reviewed": a customer rates work that was
     * completed, and a disputed job is not rated until the dispute settles.
     */
    public function isFinished(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether the job is still expected to happen.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Pending, self::Quoted, self::Accepted,
            self::Scheduled, self::OnTheWay, self::InProgress => true,
            self::Completed, self::Cancelled, self::Disputed => false,
        };
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

    /**
     * The statuses of work still expected to happen.
     *
     * Derived from {@see isActive()} rather than listed again, so the
     * dashboard's "active bookings" figure cannot drift from the state machine
     * as states are added.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isActive()),
        ));
    }
}
