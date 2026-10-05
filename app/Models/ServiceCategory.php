<?php

namespace App\Models;

use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'icon', 'sort_order', 'is_active'])]
class ServiceCategory extends Model
{
    /** @use HasFactory<ServiceCategoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to categories customers are allowed to see.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order the category grid is displayed in.
     *
     * Name is the tiebreaker so that two categories sharing a sort_order do not
     * swap places between requests — an unstable listing looks like a bug.
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The services filed under this category.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
