<?php

namespace App\Models;

use App\Support\Geo;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['name', 'region', 'latitude', 'longitude', 'is_active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Cast to float, not string: these are fed straight into haversine
            // arithmetic, and a decimal cast would hand back "-0.3031000"
            // strings that silently coerce. The column stays decimal so the
            // stored value is exact.
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to locations customers can select.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Limit the query to locations that can take part in a distance search.
     *
     * A location without coordinates cannot be measured against a search
     * point, so radius filtering excludes it rather than treating it as
     * distance zero.
     */
    #[Scope]
    protected function geocoded(Builder $query): Builder
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /** "Nakuru, Nakuru County" */
    public function label(): string
    {
        return $this->region ? "{$this->name}, {$this->region}" : $this->name;
    }

    /**
     * The ids of every town within a radius of a point.
     *
     * Exists because a radius cannot be expressed in portable SQL: haversine
     * needs trig functions that PostgreSQL has and the SQLite test database does
     * not. The town table is small and changes rarely, so resolving the radius
     * to a set of ids in PHP lets every caller filter with a plain `whereIn`
     * that behaves identically on both engines and uses the index.
     *
     * A town without coordinates is excluded rather than treated as distance
     * zero — see `geocoded()`.
     *
     * @return Collection<int, int>
     */
    public static function idsWithin(float $latitude, float $longitude, float $radiusKm): Collection
    {
        return self::query()
            ->geocoded()
            ->get(['id', 'latitude', 'longitude'])
            ->filter(fn (self $location): bool => ($location->distanceFrom(
                $latitude,
                $longitude,
            ) ?? INF) <= $radiusKm)
            ->pluck('id');
    }

    /**
     * Great-circle distance in kilometres from a point, or null when this
     * location has no coordinates.
     *
     * Delegates to App\Support\Geo, which is the one haversine in the codebase —
     * distance filtering, professional cards and this model all have to agree on
     * the same number.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        return Geo::distanceKm($latitude, $longitude, $this->latitude, $this->longitude);
    }
}
