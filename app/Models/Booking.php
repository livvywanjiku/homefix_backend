<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PreferredTimeSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A piece of work both sides have agreed to (spec §10).
 *
 * The price columns, `status`, `reference` and every timestamp are absent from
 * the fillable list. A booking is a contract: its terms are copied from the
 * quotation the customer accepted, and its state is moved only by
 * BookingService::transition(), which enforces the state machine in BookingStatus.
 * Nothing about a booking is something a client asserts in a payload.
 */
#[Fillable([
    'service_request_id',
    'quotation_id',
    'customer_id',
    'professional_id',
    'service_id',
    'location_id',
    'title',
    'description',
    'address',
    'latitude',
    'longitude',
])]
class Booking extends Model
{
    use HasFactory;

    /**
     * Bounded retries when the generated reference collides.
     */
    private const MAX_REFERENCE_ATTEMPTS = 5;

    /**
     * Attribute defaults, mirrored from the database, so an unsaved model
     * reports the same values the inserted row would carry.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => BookingStatus::Pending->value,
        'labour_cents' => 0,
        'materials_cents' => 0,
        'transport_cents' => 0,
        'other_cents' => 0,
        'total_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'scheduled_time_slot' => PreferredTimeSlot::class,
            'scheduled_for' => 'datetime',
            'agreed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'labour_cents' => 'integer',
            'materials_cents' => 'integer',
            'transport_cents' => 'integer',
            'other_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * Issues the reference as the row is written.
     */
    protected static function booted(): void
    {
        static::creating(function (self $booking): void {
            $booking->reference ??= self::nextReference();
        });
    }

    /**
     * A reference both sides can read down a phone line: "BKG-482913".
     */
    private static function nextReference(): string
    {
        for ($attempt = 0; $attempt < self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $candidate = 'BKG-'.random_int(100000, 999999);

            if (! self::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'BKG-'.now()->format('ymdHis').random_int(10, 99);
    }

    /**
     * Creates the booking an accepted quotation entitles the customer to.
     *
     * The scope, the address and the price are copied onto the booking rather
     * than read back through the quotation and the request. A booking is a
     * contract, and a contract whose terms are a live reference to a row somebody
     * can still edit is not one — repricing a quotation must not silently rewrite
     * what the customer agreed to pay.
     *
     * The status is `accepted`, not `pending`: by the time this runs, both sides
     * have already said yes. `pending` and `quoted` describe the other way a
     * booking comes into being — a customer approaching a named professional
     * directly, which arrives in a later slice.
     *
     * No date is set. The customer's preferred date lives on the request and is
     * a wish; `scheduled_for` is an appointment, and it is filled in when the
     * professional confirms one. Copying the former into the latter would record
     * a commitment nobody made.
     */
    public static function fromQuotation(ServiceRequest $serviceRequest, Quotation $quotation): self
    {
        $attributes = [
            'service_request_id' => $serviceRequest->id,
            'quotation_id' => $quotation->id,
            'customer_id' => $serviceRequest->customer_id,
            'professional_id' => $quotation->professional_id,
            'service_id' => $serviceRequest->service_id,
            'location_id' => $serviceRequest->location_id,
            'title' => $serviceRequest->title,
            'description' => $serviceRequest->description,
            'address' => $serviceRequest->address,
            'latitude' => $serviceRequest->latitude,
            'longitude' => $serviceRequest->longitude,
        ];

        $terms = [
            'status' => BookingStatus::Accepted,
            'agreed_at' => now(),
            'labour_cents' => $quotation->labour_cents,
            'materials_cents' => $quotation->materials_cents,
            'transport_cents' => $quotation->transport_cents,
            'other_cents' => $quotation->other_cents,
            'total_cents' => $quotation->total_cents,
        ];

        try {
            return self::write($attributes, $terms);
        } catch (UniqueConstraintViolationException $exception) {
            // Only a reference collision is retryable — see Quotation::createFor().
            if (! str_contains($exception->getMessage(), 'reference')) {
                throw $exception;
            }

            return self::write($attributes, $terms);
        }
    }

    /**
     * Inserts the booking, then stamps the terms that are not fillable.
     *
     * Two steps rather than one because the terms include `status` and the price
     * columns, which are kept out of the fillable list on purpose — see the class
     * note. `forceFill` is the only writer for them.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $terms
     */
    private static function write(array $attributes, array $terms): self
    {
        $booking = self::create($attributes);

        $booking->forceFill($terms)->save();

        return $booking;
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Every move this booking has made, oldest first.
     *
     * The timeline both sides read, and the record an administrator arbitrates
     * from. Ordered here rather than at each call site so the booking detail,
     * the professional's view and the admin console cannot show the same history
     * in three different orders.
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->chronological();
    }

    /**
     * The bookings a customer is tracking.
     */
    #[Scope]
    protected function forCustomer(Builder $query, User $customer): Builder
    {
        return $query->where('customer_id', $customer->id);
    }

    /**
     * The bookings a professional has taken on.
     */
    #[Scope]
    protected function forProfessional(Builder $query, User $professional): Builder
    {
        return $query->where('professional_id', $professional->id);
    }

    /**
     * Work that is still expected to happen.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BookingStatus::Pending->value,
            BookingStatus::Quoted->value,
            BookingStatus::Accepted->value,
            BookingStatus::Scheduled->value,
            BookingStatus::OnTheWay->value,
            BookingStatus::InProgress->value,
        ]);
    }

    /**
     * The eager-load set shared by both dashboards and the booking detail, so
     * they cannot drift into different N+1 shapes.
     */
    #[Scope]
    protected function forDisplay(Builder $query): Builder
    {
        return $query->with([
            'service:id,name,slug,service_category_id',
            'location:id,name,region',
            'customer:id,name,avatar_path',
            'professional:id,name,avatar_path',
            'professional.professionalProfile:id,user_id,business_name',
        ]);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * Whether a review may be left against it.
     *
     * Only finished work is reviewable, and only once — the `reviews` table
     * carries a unique index on `booking_id`, which is the guarantee rather than
     * this predicate. See the review flow.
     */
    public function isReviewable(): bool
    {
        return $this->isFinished();
    }
}
