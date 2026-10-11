<?php

namespace Database\Factories;

use App\Enums\QuotationItemCategory;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `reference` and `valid_until` are left to the model's `creating` hook, so a
     * factory-made row goes through exactly the same issuing path as one
     * submitted through the API. A test that needs a specific deadline states it
     * explicitly — see `expired()` and `validUntil()`.
     *
     * No lines are created by default, so the totals start at zero. A test that
     * needs a price uses `priced()`, which goes through the same line-writing path
     * a real quotation does rather than assigning the cached columns directly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_request_id' => ServiceRequest::factory(),
            'professional_id' => User::factory()->professional(),

            'message' => fake()->sentence(12),
            'estimated_days' => fake()->numberBetween(1, 10),
            'available_from' => null,

            'status' => QuotationStatus::Pending,
        ];
    }

    public function forRequest(ServiceRequest $serviceRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'service_request_id' => $serviceRequest->id,
        ]);
    }

    public function by(User $professional): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_id' => $professional->id,
        ]);
    }

    /**
     * A priced quotation: one line for the whole job.
     *
     * One line rather than four because most tests care about the total, and the
     * breakdown is exercised properly by the tests that are about the breakdown.
     * Either way the totals are produced by `QuotationItem`'s events, so a factory
     * quotation cannot end up with a headline figure its lines do not support.
     */
    public function priced(int $totalCents = 500000): static
    {
        return $this->afterCreating(function (Quotation $quotation) use ($totalCents): void {
            $quotation->items()->create([
                'category' => QuotationItemCategory::Labour,
                'description' => 'Labour',
                'quantity' => 1,
                'unit_price_cents' => $totalCents,
                'sort_order' => 0,
            ]);
        });
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuotationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuotationStatus::Rejected,
            'rejected_at' => now(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QuotationStatus::Withdrawn,
            'withdrawn_at' => now(),
        ]);
    }

    public function validUntil(\DateTimeInterface $moment): static
    {
        return $this->state(fn (array $attributes) => ['valid_until' => $moment]);
    }

    /**
     * Lapsed: the price deadline is in the past. The status is deliberately left
     * as `pending`, because that is the state the expiry sweep exists to find — a
     * row already marked expired would not exercise the sweep or the model's own
     * `hasExpired()` check.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_until' => now()->subDay(),
        ]);
    }
}
