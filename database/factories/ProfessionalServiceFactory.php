<?php

namespace Database\Factories;

use App\Enums\PricingType;
use App\Models\ProfessionalProfile;
use App\Models\ProfessionalService;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalService>
 */
class ProfessionalServiceFactory extends Factory
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
            'service_id' => Service::factory(),
            'description' => fake()->sentence(12),

            // The default is "quote on inspection" so that a factory-made row
            // never claims a price it does not have.
            'pricing_type' => PricingType::Quote,
            'price_min_cents' => null,
            'price_max_cents' => null,
            'is_active' => true,
        ];
    }

    /**
     * A fixed price, optionally as a range.
     */
    public function fixed(int $minCents, ?int $maxCents = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => PricingType::Fixed,
            'price_min_cents' => $minCents,
            'price_max_cents' => $maxCents,
        ]);
    }

    /**
     * An hourly rate, stored in the same minor-unit column so the search price
     * filter needs no special case.
     */
    public function hourly(int $perHourCents): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => PricingType::Hourly,
            'price_min_cents' => $perHourCents,
            'price_max_cents' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function forService(Service $service): static
    {
        return $this->state(fn (array $attributes) => ['service_id' => $service->id]);
    }

    public function forProfile(ProfessionalProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_profile_id' => $profile->id,
        ]);
    }
}
