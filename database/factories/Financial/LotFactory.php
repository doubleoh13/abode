<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'financial_commodity_id' => Commodity::factory(),
            'acquired_at' => fake()->date(),
            'cost' => fake()->numberBetween(1_000, 1_000_000),
            'metadata' => [],
        ];
    }

    public function ofCommodity(Commodity $commodity): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_commodity_id' => $commodity->id,
        ]);
    }
}
