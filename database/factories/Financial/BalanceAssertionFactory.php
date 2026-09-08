<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\Commodity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BalanceAssertion>
 */
class BalanceAssertionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'financial_account_id' => Account::factory(),
            'financial_commodity_id' => Commodity::factory(),
            'asserted_at' => fake()->date(),
            'balance' => fake()->numberBetween(0, 1_000_000),
            'memo' => null,
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_account_id' => $account->id,
        ]);
    }

    public function ofCommodity(Commodity $commodity): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_commodity_id' => $commodity->id,
        ]);
    }
}
