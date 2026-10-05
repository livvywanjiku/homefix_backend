<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single blocked date (spec §18).
 */
#[Fillable(['professional_profile_id', 'blocked_on', 'reason'])]
class AvailabilityException extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'blocked_on' => 'date',
        ];
    }

    public function professionalProfile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
    }

    /**
     * Blocks from today onwards. Past dates are kept for the record but never
     * affect booking, so the editor and the availability check both filter
     * them out here rather than in each caller.
     */
    #[Scope]
    protected function upcoming(Builder $query): Builder
    {
        return $query->whereDate('blocked_on', '>=', today());
    }
}
