<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name,
            'region' => fake()->state(),
            // Coordinates are generated inside Kenya's bounding box rather than
            // anywhere on Earth, so distance-search tests exercise realistic
            // separations instead of antipodal ones.
            'latitude' => fake()->latitude(-4.7, 5.0),
            'longitude' => fake()->longitude(33.9, 41.9),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the location is hidden from customers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Indicate that the location has not been geocoded yet.
     *
     * Such a location is still selectable but must be excluded from any
     * distance-filtered search.
     */
    public function ungeocoded(): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => null,
            'longitude' => null,
        ]);
    }
}
