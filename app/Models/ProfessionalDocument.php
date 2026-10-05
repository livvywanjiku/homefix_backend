<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `status`, `notes` and the review columns are not fillable: they record a
 * reviewer's decision, so a submission cannot arrive already approved. They are
 * written by approve()/reject() only.
 */
#[Fillable(['professional_profile_id', 'type', 'path'])]
class ProfessionalDocument extends Model
{
    use HasFactory;

    /**
     * Mirrors the column default: a document is unreviewed until an
     * administrator says otherwise, and `status` is not fillable, so a fresh
     * model would otherwise report null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => VerificationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => VerificationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function professionalProfile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', VerificationStatus::Pending->value);
    }

    /**
     * Documents are reviewed one at a time; a rejection carries the reason.
     */
    public function approve(User $reviewer): void
    {
        $this->forceFill([
            'status' => VerificationStatus::Verified,
            'notes' => null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();
    }

    public function reject(User $reviewer, string $reason): void
    {
        $this->forceFill([
            'status' => VerificationStatus::Rejected,
            'notes' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();
    }
}
