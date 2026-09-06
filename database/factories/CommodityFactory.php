<?php

namespace Database\Factories;

use App\Enums\CommodityKind;
use App\Enums\SymbolPlacement;
use App\Models\Commodity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commodity>
 */
class CommodityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('?????')),
            'name' => fake()->company(),
            'kind' => CommodityKind::Traded,
            'precision' => 4,
            'symbol' => null,
            'symbol_placement' => null,
        ];
    }

    public function currency(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => CommodityKind::Currency,
            'precision' => 2,
            'symbol' => '$',
            'symbol_placement' => SymbolPlacement::Prefix,
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => CommodityKind::Custom,
            'precision' => 0,
        ]);
    }
}
