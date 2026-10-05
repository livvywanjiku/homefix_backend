<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The fillable list is the whole of what a professional may set about
 * themselves. Verification, rating and job-count columns are absent from it —
 * a mass-assignment payload cannot mark its own profile verified — and are
 * written only by the verification and review flows, via forceFill.
 */
#[Fillable([
    'user_id',
    'business_name',
    'about',
    'experience_years',
    'phone',
    'base_location_id',
])]
class ProfessionalProfile extends Model
{
    use HasFactory;

    /**
     * Attribute defaults, mirrored from the database.
     *
     * `verification_status` especially: it is not fillable, so a profile
     * created through the setup form would carry a null status between the
     * insert and the next read, and the resource would fail on it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'verification_status' => VerificationStatus::Pending->value,
        'rating_avg' => 0,
        'rating_count' => 0,
        'completed_jobs_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'completed_jobs_count' => 'integer',
            'experience_years' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function baseLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'base_location_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProfessionalService::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProfessionalDocument::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class);
    }

    /**
     * The towns this professional covers, as a many-to-many through the
     * `professional_locations` pivot.
     */
    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'professional_locations')->withTimestamps();
    }

    /**
     * Profiles a customer has saved. Used to decide whether to render the
     * favourites button in its filled state.
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    /**
     * Only verified professionals are shown to customers (spec §13). Every
     * public-facing query starts here.
     */
    #[Scope]
    protected function verified(Builder $query): Builder
    {
        return $query->where('verification_status', VerificationStatus::Verified->value);
    }

    #[Scope]
    protected function pendingVerification(Builder $query): Builder
    {
        return $query->where('verification_status', VerificationStatus::Pending->value);
    }

    /**
     * The eager-load set for a public profile card. Centralised so search
     * results, the profile page and the favourites list cannot drift into
     * different N+1 shapes.
     */
    #[Scope]
    protected function forListing(Builder $query): Builder
    {
        return $query->with(['user:id,name,avatar_path', 'baseLocation:id,name,region']);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::Verified;
    }

    /**
     * The lowest advertised price across active services, in minor units —
     * the "from KSh 1,500" figure on a search card.
     *
     * Null when every service is priced on inspection, so the card reads
     * "Quote" rather than "KSh 0". Requires `services` to be loaded.
     */
    public function lowestPriceCents(): ?int
    {
        return $this->services
            ->where('is_active', true)
            ->pluck('price_min_cents')
            ->filter(fn (?int $cents): bool => $cents !== null)
            ->min();
    }

    /**
     * Whether the professional covers a given town — including their base, so
     * someone who works from Nakuru is matched by a Nakuru search without
     * having to list it twice.
     */
    public function coversLocation(int $locationId): bool
    {
        if ($this->base_location_id === $locationId) {
            return true;
        }

        return $this->serviceAreas->contains('id', $locationId);
    }
}
