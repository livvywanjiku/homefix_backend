<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PreferredTimeSlot;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default does not tie itself to a service request, so its customer and
     * professional are independent accounts rather than the pair a real booking
     * would describe. That is deliberate — a factory row is a shape, not a
     * scenario — and `forRequest()` is how a test asks for the coherent version.
     *
     * Bookings made through the application do not come from here at all: they
     * come from `Booking::fromQuotation()`, which is the only path that copies
     * the agreed terms across. A factory booking carries a price directly, which
     * is fine for testing the lifecycle and wrong for testing the award.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_request_id' => null,
            'quotation_id' => null,

            'customer_id' => User::factory()->customer(),
            'professional_id' => User::factory()->professional(),

            'service_id' => Service::factory(),
            'location_id' => Location::factory(),

            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),

            'status' => BookingStatus::Accepted,
            'total_cents' => 500000,
            'agreed_at' => now(),
        ];
    }

    /**
     * A booking attached to the job it came from, and belonging to that job's
     * customer — the two facts that have to agree for the row to make sense.
     */
    public function forRequest(ServiceRequest $serviceRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'service_request_id' => $serviceRequest->id,
            'customer_id' => $serviceRequest->customer_id,
            'service_id' => $serviceRequest->service_id,
            'location_id' => $serviceRequest->location_id,
            'title' => $serviceRequest->title,
            'description' => $serviceRequest->description,
        ]);
    }

    public function forProfessional(User $professional): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_id' => $professional->id,
        ]);
    }

    public function scheduledFor(\DateTimeInterface $moment, ?PreferredTimeSlot $slot = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Scheduled,
            'scheduled_for' => $moment,
            'scheduled_time_slot' => $slot ?? PreferredTimeSlot::Morning,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Completed,
            'started_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(?User $by = null, string $reason = 'The customer changed their mind.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by?->id,
            'cancellation_reason' => $reason,
        ]);
    }
}
