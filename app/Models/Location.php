<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     * Great-circle distance in kilometres from a point, or null when this
     * location has no coordinates.
     *
     * Computed in PHP rather than SQL so the same formula works on PostgreSQL
     * and on the SQLite test database, which has no trig functions.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $earthRadiusKm = 6371.0;

        $latDelta = deg2rad($this->latitude - $latitude);
        $lngDelta = deg2rad($this->longitude - $longitude);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($this->latitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
