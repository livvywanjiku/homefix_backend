<?php

namespace Database\Factories;

use App\Models\PortfolioImage;
use App\Models\PortfolioItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioImage>
 */
class PortfolioImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_item_id' => PortfolioItem::factory(),

            // A path on the `public` disk, not a real file: tests that need a
            // readable upload use Storage::fake and create one.
            'path' => 'portfolio/'.fake()->uuid().'.jpg',
            'caption' => fake()->optional()->sentence(4),
            'is_cover' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * The photo shown on the gallery card.
     */
    public function cover(): static
    {
        return $this->state(fn (array $attributes) => ['is_cover' => true]);
    }

    public function forItem(PortfolioItem $item): static
    {
        return $this->state(fn (array $attributes) => [
            'portfolio_item_id' => $item->id,
        ]);
    }
}
