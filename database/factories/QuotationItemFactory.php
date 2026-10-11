<?php

namespace Database\Factories;

use App\Enums\QuotationItemCategory;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationItem>
 */
class QuotationItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `total_cents` is absent on purpose: it is computed by the model on save, and
     * a factory that set it would be able to produce a line whose total disagrees
     * with its own quantity and unit price — exactly the state the model exists
     * to prevent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'category' => QuotationItemCategory::Labour,
            'description' => fake()->sentence(3),

            // Whole units by default. A part-unit quantity is a deliberate case,
            // not the common one.
            'quantity' => 1,
            'unit_price_cents' => fake()->numberBetween(10000, 500000),
            'sort_order' => 0,
        ];
    }

    public function inCategory(QuotationItemCategory $category): static
    {
        return $this->state(fn (array $attributes) => ['category' => $category]);
    }

    /**
     * A line whose arithmetic is worth checking: a part-unit quantity at a price
     * that does not divide evenly.
     */
    public function quantity(float $quantity, int $unitPriceCents): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
        ]);
    }
}
