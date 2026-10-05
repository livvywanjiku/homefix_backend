<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\Location;
use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalProfile>
 */
class ProfessionalProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The account the profile belongs to. Created with the professional
            // role so a test can authenticate as it without a second step.
            'user_id' => User::factory()->professional(),
            'business_name' => fake()->company(),
            'about' => fake()->paragraphs(2, true),
            'experience_years' => fake()->numberBetween(1, 30),
            'phone' => fake()->numerify('+2547########'),
            'base_location_id' => Location::factory(),

            // A new applicant starts unreviewed with no reputation, which is
            // what the search filters must exclude.
            'verification_status' => VerificationStatus::Pending,
            'verified_at' => null,
            'rating_avg' => 0,
            'rating_count' => 0,
            'completed_jobs_count' => 0,
        ];
    }

    /**
     * A professional customers can find, book and review.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'The uploaded ID could not be read.'): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Rejected,
            'verification_notes' => $reason,
        ]);
    }

    public function suspended(string $reason = 'Suspended pending investigation.'): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => VerificationStatus::Suspended,
            'verification_notes' => $reason,
        ]);
    }

    /**
     * An established reputation, for ranking and review-display tests.
     */
    public function rated(float $average, int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'rating_avg' => $average,
            'rating_count' => $count,
        ]);
    }

    /**
     * Attach the profile to an account that already exists rather than minting
     * a new one — used when the test needs to sign in as that specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $user->id]);
    }

    public function basedIn(Location $location): static
    {
        return $this->state(fn (array $attributes) => [
            'base_location_id' => $location->id,
        ]);
    }
}
