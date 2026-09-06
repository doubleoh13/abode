<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Payee;
use App\Models\Financial\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeThisYear()->format('Y-m-d'),
            'financial_payee_id' => null,
            'memo' => null,
            'metadata' => [],
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    public function withPayee(Payee $payee): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_payee_id' => $payee->id,
        ]);
    }
}
