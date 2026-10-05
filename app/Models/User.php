<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Attribute defaults, mirrored from the database so that an unsaved model
     * reports the same status the inserted row would carry.
     *
     * `status` is intentionally absent from the fillable list: it is
     * security relevant and only ever set explicitly by the suspension flow.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => UserStatus::Active->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * Limit the query to accounts that are not suspended.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin->value);
    }

    public function isCustomer(): bool
    {
        return $this->hasRole(UserRole::Customer->value);
    }

    public function isProfessional(): bool
    {
        return $this->hasRole(UserRole::Professional->value);
    }

    /**
     * The single role this account acts as, or null when roles are unsynced.
     */
    public function primaryRole(): ?UserRole
    {
        return $this->roles
            ->map(fn ($role): ?UserRole => UserRole::tryFrom($role->name))
            ->filter()
            ->first();
    }

    /**
     * The professional profile this account trades under, when it has one.
     *
     * Only professionals have a profile, and a professional has exactly one —
     * `professional_profiles.user_id` is unique.
     */
    public function professionalProfile(): HasOne
    {
        return $this->hasOne(ProfessionalProfile::class);
    }

    /**
     * Saved professionals, as pivot rows — used to toggle a favourite.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * The saved professionals themselves, for rendering the list.
     */
    public function favoriteProfessionals(): BelongsToMany
    {
        return $this->belongsToMany(ProfessionalProfile::class, 'favorites')->withTimestamps();
    }
}
