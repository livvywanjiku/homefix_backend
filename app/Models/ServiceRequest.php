<?php

namespace App\Models;

use App\Enums\PreferredTimeSlot;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestUrgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A customer's job posting (spec §8).
 *
 * `reference`, `status`, `quotes_count` and `expires_at` are deliberately not
 * fillable. The first is issued by the platform, the second and third are
 * written only by the transitions below, and the fourth is a deadline the
 * platform sets — none of them is something a customer should be able to assert
 * in a create payload.
 */
#[Fillable([
    'customer_id',
    'service_id',
    'location_id',
    'title',
    'description',
    'instructions',
    'address',
    'latitude',
    'longitude',
    'preferred_date',
    'preferred_time_slot',
    'budget_min_cents',
    'budget_max_cents',
    'urgency',
])]
class ServiceRequest extends Model
{
    use HasFactory;

    /**
     * How long a posting stays on the board before it lapses.
     *
     * Two weeks is long enough for a customer who posts over a weekend to
     * collect several quotes, and short enough that professionals are not
     * reading listings whose owner has long since hired somebody else.
     *
     * This is the *default*. An administrator can change it in the console, and
     * {@see lifetimeDays()} is what the creating hook reads.
     */
    public const LIFETIME_DAYS = 14;

    /** The settings key the console writes and this model reads. */
    public const LIFETIME_SETTING = 'marketplace.request_lifetime_days';

    /**
     * The most photos one request may carry in total.
     *
     * A request is a description with supporting evidence, not a photo album —
     * and every extra image is bandwidth a professional pays for while browsing
     * the board.
     */
    public const MAX_IMAGES = 5;

    /**
     * Bounded retries when the generated reference collides.
     *
     * The unique index is the real guarantee; this loop only turns the
     * vanishingly rare collision into a slower insert instead of a 500.
     */
    private const MAX_REFERENCE_ATTEMPTS = 5;

    /**
     * Attribute defaults, mirrored from the database.
     *
     * `status` and `quotes_count` are not fillable, so without these a freshly
     * created model would report null for both until it was re-read.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ServiceRequestStatus::Open->value,
        'urgency' => ServiceRequestUrgency::Flexible->value,
        'quotes_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceRequestStatus::class,
            'urgency' => ServiceRequestUrgency::class,
            'preferred_time_slot' => PreferredTimeSlot::class,
            'preferred_date' => 'date',
            'expires_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'budget_min_cents' => 'integer',
            'budget_max_cents' => 'integer',
            'quotes_count' => 'integer',
        ];
    }

    /**
     * Issues the reference and the deadline as the row is written.
     *
     * Both belong to the platform rather than to the customer, and both have to
     * exist at insert time: `reference` is NOT NULL, and a request that lapsed
     * on the way in would be a bug rather than a state.
     */
    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->reference ??= self::nextReference();
            $request->expires_at ??= now()->addDays(self::lifetimeDays());
        });
    }

    /**
     * How many days a posting lives, as configured.
     *
     * Read at the moment a request is created rather than at render time, so a
     * change to the setting applies to new postings and leaves the deadline the
     * platform already promised an existing one alone.
     */
    public static function lifetimeDays(): int
    {
        return max(1, Setting::int(self::LIFETIME_SETTING, self::LIFETIME_DAYS));
    }

    /**
     * A reference the customer can read down a phone line: "REQ-482913".
     *
     * Six digits keeps it short enough to say aloud and wide enough that the
     * existence check almost never fires twice in a row.
     */
    private static function nextReference(): string
    {
        for ($attempt = 0; $attempt < self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $candidate = 'REQ-'.random_int(100000, 999999);

            if (! self::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        /*
         * Every attempt collided, which means either pathological luck or a
         * saturated space. Fall back to something time-ordered and wide; the
         * unique index still refuses a duplicate, and `createFor()` retries.
         */
        return 'REQ-'.now()->format('ymdHis').random_int(10, 99);
    }

    /**
     * Creates a request, retrying once if the reference raced with another.
     *
     * Two customers posting in the same millisecond can both pass the existence
     * check above and only collide at the index. Catching it here keeps that
     * from surfacing as a 500 to one of them.
     */
    public static function createFor(array $attributes): self
    {
        try {
            return self::create($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            // Only a reference collision is retryable; anything else (a bad
            // foreign key, say) is a real error and must not be swallowed.
            if (! str_contains($exception->getMessage(), 'reference')) {
                throw $exception;
            }

            return self::create($attributes);
        }
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ServiceRequestImage::class)->orderBy('sort_order');
    }

    /**
     * The price offers this job has attracted.
     *
     * Every one of them, not only the live ones: the customer's comparison table
     * and the professional's own list both need to show what was turned down and
     * what was withdrawn, because those are the record of how the decision was
     * reached.
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * The work this job produced, once a quotation was accepted.
     *
     * A collection rather than a single relation even though only one booking can
     * exist per request today: `bookings.service_request_id` is nullable with
     * `nullOnDelete` precisely so a booking can outlive its advertisement, and a
     * `hasOne` here would be a promise about cardinality that the schema does not
     * make.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Requests still on the board: unanswered or awaiting more quotes.
     *
     * The deadline is re-checked here rather than relied on from `status`,
     * because the expiry sweep runs on a schedule and a listing should stop
     * being visible the moment it lapses, not the next time the sweep happens.
     */
    #[Scope]
    protected function listed(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                ServiceRequestStatus::Open->value,
                ServiceRequestStatus::Quoted->value,
            ])
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    #[Scope]
    protected function ownedBy(Builder $query, User $customer): Builder
    {
        return $query->where('customer_id', $customer->id);
    }

    /**
     * The eager-load set shared by the customer's list and the request detail.
     *
     * Centralised so the two cannot drift into different N+1 shapes.
     */
    #[Scope]
    protected function forDisplay(Builder $query): Builder
    {
        return $query->with([
            'service:id,name,slug,service_category_id',
            'service.category:id,name,slug',
            'location:id,name,region',
        ]);
    }

    public function isOpen(): bool
    {
        return $this->status === ServiceRequestStatus::Open;
    }

    public function acceptsQuotations(): bool
    {
        return $this->status->acceptsQuotations() && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable() && ! $this->hasExpired();
    }

    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }

    /**
     * Records that the first quotation has arrived.
     *
     * Called by the quotation flow rather than by a customer, which is why it
     * is a named transition instead of a status assignment.
     */
    public function markQuoted(): void
    {
        if ($this->status === ServiceRequestStatus::Open) {
            $this->forceFill(['status' => ServiceRequestStatus::Quoted])->save();
        }
    }

    /**
     * The request has produced a booking. It comes off the board for good.
     */
    public function markAwarded(): void
    {
        $this->forceFill(['status' => ServiceRequestStatus::Awarded])->save();
    }

    public function cancel(): void
    {
        $this->forceFill(['status' => ServiceRequestStatus::Cancelled])->save();
    }

    public function close(): void
    {
        $this->forceFill(['status' => ServiceRequestStatus::Closed])->save();
    }

    public function markExpired(): void
    {
        $this->forceFill(['status' => ServiceRequestStatus::Expired])->save();
    }
}
