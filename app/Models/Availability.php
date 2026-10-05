<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per day the professional works. See the migration for why absence of
 * a row is the "unavailable" signal.
 */
#[Fillable(['professional_profile_id', 'day_of_week', 'start_time', 'end_time'])]
class Availability extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function professionalProfile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
    }

    #[Scope]
    protected function onDay(Builder $query, int $dayOfWeek): Builder
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    /**
     * Sunday-first display order for the weekly grid on the profile and the
     * /pro availability editor.
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('day_of_week')->orderBy('start_time');
    }
}
