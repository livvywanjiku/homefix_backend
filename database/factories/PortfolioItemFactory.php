<?php

namespace Database\Factories;

use App\Models\PortfolioItem;
use App\Models\ProfessionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioItem>
 */
class PortfolioItemFactory extends Factory
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

            // A general album rather than one filed under a catalogue service:
            // a job title alone is enough to render a card.
            'service_id' => null,
            'location_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'completed_on' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'sort_order' => 0,
            'is_published' => true,
        ];
    }

    /**
     * A draft the professional has not put on their public profile yet.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['is_published' => false]);
    }

    public function forProfile(ProfessionalProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_profile_id' => $profile->id,
        ]);
    }
}
