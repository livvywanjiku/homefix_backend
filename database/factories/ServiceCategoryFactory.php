<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A profession noun phrase, so the slug reads like a real category
        // ("drain-cleaning") rather than the lorem ipsum a sentence would give.
        $name = fake()->unique()->randomElement([
            'Plumbing', 'Electrical', 'Cleaning', 'Carpentry', 'Painting',
            'Gardening', 'Appliance Repair', 'Masonry', 'Roofing', 'Pest Control',
            'Moving', 'Laundry', 'Air Conditioning', 'Security Systems',
            'General Maintenance',
        ]).' '.fake()->unique()->numerify('##');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(12),
            'icon' => fake()->randomElement(['🔧', '⚡', '🧹', '🪚', '🎨', '🌿']),
            'sort_order' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the category is hidden from customers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
