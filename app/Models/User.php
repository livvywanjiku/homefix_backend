<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * The browser-facing URL for the uploaded avatar.
     *
     * `avatar_path` is the storage-relative path the disk needs; every API
     * consumer needs the absolute URL. Deriving it here means a resource cannot
     * accidentally emit a bare path — which is what the frontend was receiving
     * before avatars were uploadable.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->avatar_path === null
                ? null
                : url('storage/'.ltrim($this->avatar_path, '/')),
        );
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

    /**
     * Whether this account may act in the administration area.
     *
     * Both administrative roles answer true. What separates them is not
     * answered here but by the permission gates: a super administrator holds
     * every permission, an administrator holds the operational set.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(UserRole::administrativeValues());
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
     * The jobs this account has posted, as a customer.
     *
     * Named for the role rather than the table so it reads unambiguously
     * alongside the relations a professional account carries.
     */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'customer_id');
    }

    /**
     * The prices this account has offered, as a professional.
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'professional_id');
    }

    /**
     * Work this account is doing, as a professional.
     *
     * Named for the side of the booking rather than left as `bookings()`, because
     * the same account id appears in both `customer_id` and `professional_id` and
     * a single relation would silently answer the wrong question.
     */
    public function professionalBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'professional_id');
    }

    /**
     * Work this account has commissioned, as a customer.
     */
    public function customerBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    /**
     * The saved professionals themselves, for rendering the list.
     */
    public function favoriteProfessionals(): BelongsToMany
    {
        return $this->belongsToMany(ProfessionalProfile::class, 'favorites')->withTimestamps();
    }
}
