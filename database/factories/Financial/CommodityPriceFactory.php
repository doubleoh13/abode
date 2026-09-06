<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommodityPrice>
 */
class CommodityPriceFactory extends Factory
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
            'price' => fake()->randomFloat(4, 0.01, 100000),
            'priced_at' => fake()->dateTimeBetween('-2 years', 'now'),
        ];
    }
}
