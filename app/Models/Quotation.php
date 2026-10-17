<?php

namespace App\Models;

use App\Enums\QuotationItemCategory;
use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * A professional's priced offer on a customer's job posting (spec §9).
 *
 * The money columns — the four category totals and the grand total — are
 * deliberately absent from the fillable list. They are a cache of what the line
 * items sum to, recomputed by `recalculateTotals()` whenever a line changes, and
 * a price that arrives in a request body is not a price this platform has agreed
 * to. `status`, `reference` and the three decision timestamps are excluded for
 * the same reason: they are written only by the transitions below.
 */
#[Fillable([
    'service_request_id',
    'professional_id',
    'message',
    'estimated_days',
    'available_from',
    'valid_until',
])]
class Quotation extends Model
{
    use HasFactory;

    /**
     * How long a price stands when the professional does not say.
     *
     * A week is long enough for a customer to collect the other two quotes they
     * were always going to collect, and short enough that a professional is not
     * held to last month's material prices.
     */
    public const DEFAULT_VALIDITY_DAYS = 7;

    /** The settings key the console writes and this model reads. */
    public const VALIDITY_SETTING = 'marketplace.quotation_validity_days';

    /**
     * The most lines one quotation may carry.
     *
     * A breakdown is a handful of costs, not a bill of materials. The ceiling is
     * here so a quotation cannot be used as a bulk data channel, and so the
     * comparison table stays a comparison.
     */
    public const MAX_ITEMS = 20;

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
     * `status` and the money columns are not fillable, so without these a freshly
     * created model would report null for all six until it was re-read.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => QuotationStatus::Pending->value,
        'labour_cents' => 0,
        'materials_cents' => 0,
        'transport_cents' => 0,
        'other_cents' => 0,
        'total_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'available_from' => 'date',
            'valid_until' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'estimated_days' => 'integer',
            'labour_cents' => 'integer',
            'materials_cents' => 'integer',
            'transport_cents' => 'integer',
            'other_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * Issues the reference and the shelf life as the row is written.
     *
     * Both belong to the platform rather than to the professional, and both have
     * to exist at insert time: `reference` is NOT NULL and unique, and a
     * quotation with no deadline could never be expired.
     */
    protected static function booted(): void
    {
        static::creating(function (self $quotation): void {
            $quotation->reference ??= self::nextReference();
            $quotation->valid_until ??= now()->addDays(self::validityDays());
        });
    }

    /**
     * How many days a price stands, as configured.
     *
     * Read when the quotation is written, so changing the setting governs new
     * offers without retroactively shortening one a professional has already
     * made — a deadline the platform published is not the platform's to move.
     */
    public static function validityDays(): int
    {
        return max(1, Setting::int(self::VALIDITY_SETTING, self::DEFAULT_VALIDITY_DAYS));
    }

    /**
     * A reference the customer can read down a phone line: "QUO-482913".
     */
    private static function nextReference(): string
    {
        for ($attempt = 0; $attempt < self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $candidate = 'QUO-'.random_int(100000, 999999);

            if (! self::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Every attempt collided — pathological luck or a saturated space. Fall
        // back to something time-ordered and wide; the unique index still
        // refuses a duplicate, and `createFor()` retries.
        return 'QUO-'.now()->format('ymdHis').random_int(10, 99);
    }

    /**
     * Creates a quotation, retrying once if the reference raced with another.
     */
    public static function createFor(array $attributes): self
    {
        try {
            return self::create($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            /*
             * Two unique indexes can fire here: the reference, and the
             * one-quote-per-professional rule. Only the first is retryable —
             * retrying a duplicate offer would produce the same violation again
             * and mask the real answer, which is that this professional has
             * already quoted.
             */
            if (! str_contains($exception->getMessage(), 'reference')) {
                throw $exception;
            }

            return self::create($attributes);
        }
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The booking this quotation produced, once it has been accepted.
     */
    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    /**
     * The quotations on one job, for the customer's comparison table.
     *
     * Ordered so the strongest offer leads: an accepted quotation outranks a
     * live one, a live one outranks a settled one, and within a group the
     * cheapest is first. A customer opening the table wants the answer, not the
     * submission order.
     */
    #[Scope]
    protected function forRequest(Builder $query, ServiceRequest $serviceRequest): Builder
    {
        return $query
            ->where('service_request_id', $serviceRequest->id)
            ->orderByRaw(
                "case status when 'accepted' then 0 when 'pending' then 1 else 2 end",
            )
            ->orderBy('total_cents');
    }

    #[Scope]
    protected function byProfessional(Builder $query, User $professional): Builder
    {
        return $query->where('professional_id', $professional->id);
    }

    /**
     * Offers still awaiting a decision. The set the expiry sweep works on.
     */
    #[Scope]
    protected function live(Builder $query): Builder
    {
        return $query->where('status', QuotationStatus::Pending->value);
    }

    /**
     * The eager-load set shared by the comparison table, the professional's own
     * list and every single-quotation response, so the four cannot drift into
     * different N+1 shapes.
     *
     * A constant rather than only a scope because the single-model paths —
     * submit, revise, accept, withdraw — need `load()` rather than a query, and
     * two lists that are meant to be identical should not be written twice.
     *
     * The professional's profile is loaded because every display of a quotation
     * names the business rather than the account. The request's status travels
     * with it because an offer that looks live on a job cancelled last week is a
     * different thing from one awaiting a decision.
     *
     * @var array<int, string>
     */
    public const DISPLAY_RELATIONS = [
        'items',
        'booking',
        // `phone` is loaded for the post-award fallback; the resource decides
        // whether this viewer may see it.
        'professional:id,name,phone,avatar_path',
        'professional.professionalProfile:id,user_id,business_name,base_location_id,verification_status,rating_avg,rating_count,completed_jobs_count,phone',
        'serviceRequest:id,reference,title,status,expires_at,customer_id,service_id,location_id',
        // The customer is a counterparty only after the award, but the relation
        // has to be present for the resource to answer that question at all.
        'serviceRequest.customer:id,name,avatar_path,phone',
    ];

    #[Scope]
    protected function forDisplay(Builder $query): Builder
    {
        return $query->with(self::DISPLAY_RELATIONS);
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * Whether the price is still standing.
     *
     * A deadline that has passed does not wait for the sweep to be honoured —
     * see `isAcceptable()`, which checks both.
     */
    public function hasExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * Whether the customer may take this offer right now.
     *
     * Three things have to hold, and only the first is visible on the row: the
     * quotation is still live, its price has not lapsed, and the job it is for is
     * still open to being awarded. The third is what stops two tabs — or a
     * customer who left the page open — from awarding a request twice.
     */
    public function isAcceptable(): bool
    {
        return $this->isLive()
            && ! $this->hasExpired()
            && $this->serviceRequest->acceptsQuotations();
    }

    /**
     * Whether the professional may still revise it.
     *
     * Narrower than `isAcceptable()`: a price that has lapsed is not the
     * professional's to change either — they should submit a fresh offer, so the
     * customer can see that the terms moved.
     */
    public function isEditable(): bool
    {
        return $this->isLive() && ! $this->hasExpired();
    }

    /**
     * Recomputes the four category totals and the grand total from the lines.
     *
     * Called by `QuotationItem` whenever a line is written or removed, so the
     * cache cannot drift from the breakdown it summarises. It is a full
     * recomputation rather than an increment, which makes it idempotent: running
     * it twice, or out of order, still lands on the same five numbers.
     */
    public function recalculateTotals(): void
    {
        $totals = $this->items()
            ->get(['category', 'total_cents'])
            ->groupBy(fn (QuotationItem $item): string => $item->category->value)
            ->map(fn (Collection $items): int => (int) $items->sum('total_cents'));

        $labour = (int) $totals->get(QuotationItemCategory::Labour->value, 0);
        $materials = (int) $totals->get(QuotationItemCategory::Materials->value, 0);
        $transport = (int) $totals->get(QuotationItemCategory::Transport->value, 0);
        $other = (int) $totals->get(QuotationItemCategory::Other->value, 0);

        $this->forceFill([
            'labour_cents' => $labour,
            'materials_cents' => $materials,
            'transport_cents' => $transport,
            'other_cents' => $other,
            'total_cents' => $labour + $materials + $transport + $other,
        ])->save();
    }

    /**
     * The customer took this offer. Written by QuotationService, under a lock,
     * as part of the award — never directly from a controller.
     */
    public function markAccepted(): void
    {
        $this->forceFill([
            'status' => QuotationStatus::Accepted,
            'accepted_at' => now(),
        ])->save();
    }

    /**
     * Turned down individually, or displaced by a sibling that was accepted.
     */
    public function markRejected(): void
    {
        $this->forceFill([
            'status' => QuotationStatus::Rejected,
            'rejected_at' => now(),
        ])->save();
    }

    /**
     * Pulled by the professional before the customer decided.
     */
    public function withdraw(): void
    {
        $this->forceFill([
            'status' => QuotationStatus::Withdrawn,
            'withdrawn_at' => now(),
        ])->save();
    }

    /**
     * Lapsed. Written by the `quotations:expire` sweep.
     */
    public function markExpired(): void
    {
        $this->forceFill(['status' => QuotationStatus::Expired])->save();
    }
}
