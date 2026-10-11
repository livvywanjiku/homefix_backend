<?php

namespace Database\Factories;

use App\Enums\PreferredTimeSlot;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestUrgency;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `reference` and `expires_at` are left to the model's `creating` hook, so a
     * factory-made row goes through exactly the same issuing path as one posted
     * by a customer. A test that needs a specific deadline states it explicitly
     * — see `expiresAt()` and `expired()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Created as a customer so a test can authenticate as the owner
            // without a second step.
            'customer_id' => User::factory()->customer(),
            'service_id' => Service::factory(),
            'location_id' => Location::factory(),

            'title' => fake()->sentence(4),
            // Comfortably over the 20-character floor the store request
            // enforces, so a factory row is never invalid against its own rules.
            'description' => fake()->paragraphs(2, true),
            'instructions' => null,

            'address' => fake()->streetAddress(),
            'latitude' => null,
            'longitude' => null,

            'preferred_date' => null,
            'preferred_time_slot' => null,

            'budget_min_cents' => null,
            'budget_max_cents' => null,

            'urgency' => ServiceRequestUrgency::Flexible,

            // Mirrors the column default. Set here rather than left out so a
            // test reading `$request->status` before a refresh sees the real
            // value rather than null.
            'status' => ServiceRequestStatus::Open,
            'quotes_count' => 0,
        ];
    }

    public function quoted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServiceRequestStatus::Quoted,
            'quotes_count' => 1,
        ]);
    }

    public function awarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServiceRequestStatus::Awarded,
            'quotes_count' => 2,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ServiceRequestStatus::Cancelled]);
    }

    /**
     * Lapsed: the deadline is in the past. The status is deliberately left as
     * `open`, because that is the state the expiry sweep exists to find — a row
     * already marked expired would not exercise the sweep or the `listed()`
     * scope's own deadline check.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresAt(\DateTimeInterface $moment): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => $moment]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'urgency' => ServiceRequestUrgency::Urgent,
            'preferred_date' => now()->addDay(),
            'preferred_time_slot' => PreferredTimeSlot::Morning,
        ]);
    }

    /**
     * A stated budget range, in minor units.
     */
    public function withBudget(int $minCents, int $maxCents): static
    {
        return $this->state(fn (array $attributes) => [
            'budget_min_cents' => $minCents,
            'budget_max_cents' => $maxCents,
        ]);
    }

    /**
     * A job pinned to a specific point, as the map picker would leave it.
     */
    public function atCoordinates(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(fn (array $attributes) => ['customer_id' => $customer->id]);
    }

    public function forService(Service $service): static
    {
        return $this->state(fn (array $attributes) => ['service_id' => $service->id]);
    }

    public function inLocation(Location $location): static
    {
        return $this->state(fn (array $attributes) => ['location_id' => $location->id]);
    }
}
