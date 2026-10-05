<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completed job shown in the public portfolio (spec §12).
 */
#[Fillable([
    'professional_profile_id',
    'service_id',
    'location_id',
    'title',
    'description',
    'completed_on',
    'sort_order',
    'is_published',
])]
class PortfolioItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(PortfolioImage::class)->orderBy('sort_order');
    }

    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('completed_on');
    }

    /**
     * The image shown on the gallery card: the flagged cover if there is one,
     * otherwise the first by sort order.
     */
    public function coverImage(): ?PortfolioImage
    {
        return $this->images->firstWhere('is_cover', true) ?? $this->images->first();
    }
}
