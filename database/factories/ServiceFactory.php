<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' '.fake()->unique()->numerify('###');

        return [
            // A category is created on demand so that `Service::factory()->create()`
            // works on its own, without every test having to build the parent first.
            'service_category_id' => ServiceCategory::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the service is hidden from customers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * File the service under an existing category.
     */
    public function inCategory(ServiceCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'service_category_id' => $category->id,
        ]);
    }
}
