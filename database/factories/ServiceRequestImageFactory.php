<?php

namespace Database\Factories;

use App\Models\ServiceRequest;
use App\Models\ServiceRequestImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequestImage>
 */
class ServiceRequestImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The path points at nothing on disk. That is deliberate: this factory is
     * for rows, and the tests that care whether the file exists go through the
     * upload endpoint instead, which is the only place a real path is issued.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_request_id' => ServiceRequest::factory(),
            'path' => 'service-requests/'.fake()->uuid().'.jpg',
            'original_name' => fake()->word().'.jpg',
            'size_bytes' => fake()->numberBetween(40_000, 4_000_000),
            'sort_order' => 0,
        ];
    }

    public function forRequest(ServiceRequest $serviceRequest): static
    {
        return $this->state(fn (array $attributes) => [
            'service_request_id' => $serviceRequest->id,
        ]);
    }

    public function atOrder(int $order): static
    {
        return $this->state(fn (array $attributes) => ['sort_order' => $order]);
    }
}
