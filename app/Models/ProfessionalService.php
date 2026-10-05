<?php

namespace App\Models;

use App\Enums\PricingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A catalogue service as offered by one professional — the join between them is
 * a model rather than a plain pivot because it carries its own pricing and
 * description.
 */
#[Fillable([
    'professional_profile_id',
    'service_id',
    'description',
    'pricing_type',
    'price_min_cents',
    'price_max_cents',
    'is_active',
])]
class ProfessionalService extends Model
{
    use HasFactory;

    /**
     * Mirrors the column defaults, so an offering created without an explicit
     * pricing type is quote-only and live rather than null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pricing_type' => PricingType::Quote->value,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'price_min_cents' => 'integer',
            'price_max_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function professionalProfile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The lowest price this professional will quote for the service, in minor
     * units — used for the "from KSh 1,500" figure on search cards and for the
     * price-range filter.
     *
     * Returns null for quote-only work rather than 0, so it renders as "Quote"
     * instead of "KSh 0".
     */
    public function fromPriceCents(): ?int
    {
        return $this->price_min_cents;
    }
}
