<?php

namespace Database\Factories\Financial;

use App\Enums\Financial\CommodityKind;
use App\Enums\Financial\SymbolPlacement;
use App\Models\Financial\Commodity;
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
            'display_precision' => 4,
            'symbol' => null,
            'symbol_placement' => null,
        ];
    }

    public function currency(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => CommodityKind::Currency,
            'display_precision' => 2,
            'symbol' => '$',
            'symbol_placement' => SymbolPlacement::Prefix,
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => CommodityKind::Custom,
            'display_precision' => 0,
        ]);
    }
}
