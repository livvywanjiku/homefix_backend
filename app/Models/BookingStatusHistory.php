<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move a booking made (spec §10).
 *
 * Written only by {@see \App\Services\Bookings\BookingService::transition()},
 * which is also the only writer of `bookings.status`. Keeping the two writes in
 * one place is what makes this table a reliable account of what happened rather
 * than a second opinion about it.
 */
#[Fillable(['booking_id', 'from_status', 'to_status', 'changed_by', 'note'])]
class BookingStatusHistory extends Model
{
    /**
     * The table is named for what it holds, but Laravel would guess the plural
     * of the class name. Declared rather than renamed so the model reads the way
     * the migration does.
     */
    protected $table = 'booking_status_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Who made the move, when a person did.
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Oldest first, which is the order a timeline is read in.
     *
     * @param  Builder<BookingStatusHistory>  $query
     * @return Builder<BookingStatusHistory>
     */
    #[Scope]
    protected function chronological(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('id');
    }
}
