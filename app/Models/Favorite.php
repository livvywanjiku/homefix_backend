<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved professional (spec §7).
 */
#[Fillable(['user_id', 'professional_profile_id'])]
class Favorite extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function professionalProfile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
    }
}
