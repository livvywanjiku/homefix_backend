<?php

namespace Database\Factories;

use App\Models\Availability;
use App\Models\ProfessionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'professional_profile_id' => ProfessionalProfile::factory(),

            // A weekday rather than a weekend day: the default should look like
            // a working week.
            'day_of_week' => fake()->numberBetween(1, 5),
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ];
    }

    /**
     * Pin the row to a specific weekday (0 = Sunday).
     */
    public function onDay(int $dayOfWeek): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_week' => $dayOfWeek,
        ]);
    }

    /**
     * Working hours other than the default 08:00–17:00.
     */
    public function between(string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public function forProfile(ProfessionalProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_profile_id' => $profile->id,
        ]);
    }
}
