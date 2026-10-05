<?php

namespace Database\Factories;

use App\Models\AvailabilityException;
use App\Models\ProfessionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityException>
 */
class AvailabilityExceptionFactory extends Factory
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
            'blocked_on' => fake()->dateTimeBetween('now', '+2 months')->format('Y-m-d'),
            'reason' => fake()->randomElement(['Public holiday', 'Annual leave', 'Fully booked']),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => ['blocked_on' => $date]);
    }

    /**
     * A date that has already passed — used to prove the availability check
     * and the editor's list both ignore history.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'blocked_on' => now()->subWeek()->toDateString(),
        ]);
    }

    public function forProfile(ProfessionalProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_profile_id' => $profile->id,
        ]);
    }
}
