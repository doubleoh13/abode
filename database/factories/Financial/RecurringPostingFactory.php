<?php

namespace Database\Factories\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\RecurringPosting;
use App\Models\Financial\RecurringTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPosting>
 */
class RecurringPostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'financial_recurring_transaction_id' => RecurringTransaction::factory(),
            'position' => 0,
            'status' => null,
            'financial_account_id' => Account::factory(),
            'financial_commodity_id' => Commodity::factory(),
            'amount' => fake()->randomElement([-1, 1]) * fake()->numberBetween(1, 100_000),
            'memo' => null,
            'metadata' => [],
        ];
    }

    public function forSchedule(RecurringTransaction $schedule, int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_recurring_transaction_id' => $schedule->id,
            'position' => $position,
        ]);
    }

    public function inAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_account_id' => $account->id,
            'status' => in_array($account->account_type, [AccountType::Asset, AccountType::Liability], true)
                ? PostingStatus::Pending
                : null,
        ]);
    }

    public function ofCommodity(Commodity $commodity): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_commodity_id' => $commodity->id,
        ]);
    }
}
