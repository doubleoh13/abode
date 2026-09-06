<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Payee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payee>
 */
class PayeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }
}
