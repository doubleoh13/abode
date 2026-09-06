<?php

namespace Database\Factories\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Posting>
 */
class PostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'financial_transaction_id' => Transaction::factory(),
            'position' => 0,
            'status' => null,
            'financial_account_id' => Account::factory(),
            'financial_commodity_id' => Commodity::factory(),
            'financial_lot_id' => null,
            'amount' => fake()->randomElement([-1, 1]) * fake()->numberBetween(1, 100_000),
            'memo' => null,
            'metadata' => [],
        ];
    }

    public function forTransaction(Transaction $transaction, int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_transaction_id' => $transaction->id,
            'position' => $position,
        ]);
    }

    public function inAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_account_id' => $account->id,
            'status' => in_array($account->account_type, [AccountType::Asset, AccountType::Liability], true)
                ? PostingStatus::Cleared
                : null,
        ]);
    }

    public function ofCommodity(Commodity $commodity): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_commodity_id' => $commodity->id,
        ]);
    }

    public function withLot(Lot $lot): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_lot_id' => $lot->id,
            'financial_commodity_id' => $lot->financial_commodity_id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostingStatus::Pending,
        ]);
    }

    public function reconciled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostingStatus::Reconciled,
        ]);
    }
}
