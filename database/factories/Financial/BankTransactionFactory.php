<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Posting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $postedOn = fake()->dateTimeThisYear()->format('Y-m-d');

        return [
            'financial_account_id' => Account::factory(),
            'source' => BankTransaction::SOURCE_SIMPLEFIN,
            'external_id' => fake()->unique()->uuid(),
            'posted_on' => $postedOn,
            'transacted_on' => $postedOn,
            'pending' => false,
            'amount' => (string) (fake()->randomElement([-1, 1]) * fake()->numberBetween(1, 50_000) / 100),
            'currency' => 'USD',
            'description' => fake()->company(),
            'payee' => null,
            'memo' => null,
            'payload' => [],
            'financial_posting_id' => null,
            'ignored_at' => null,
        ];
    }

    public function inAccount(Account $account): static
    {
        return $this->state(fn (array $attributes) => ['financial_account_id' => $account->id]);
    }

    public function linkedTo(Posting $posting): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_account_id' => $posting->financial_account_id,
            'financial_posting_id' => $posting->id,
            'amount' => (string) $posting->amount,
        ]);
    }
}
