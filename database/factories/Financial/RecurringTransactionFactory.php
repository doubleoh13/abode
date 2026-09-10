<?php

namespace Database\Factories\Financial;

use App\Enums\Financial\RecurrenceFrequency;
use App\Models\Financial\Payee;
use App\Models\Financial\RecurringTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransaction>
 */
class RecurringTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeThisYear()->format('Y-m-d');

        return [
            'financial_payee_id' => null,
            'memo' => null,
            'metadata' => [],
            'frequency' => RecurrenceFrequency::Monthly,
            'interval' => 1,
            'starts_on' => $startsOn,
            'next_due_on' => $startsOn,
            'ends_on' => null,
            'lead_days' => null,
        ];
    }

    public function startingOn(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => $date,
            'next_due_on' => $date,
        ]);
    }

    public function every(RecurrenceFrequency $frequency, int $interval = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => $frequency,
            'interval' => $interval,
        ]);
    }

    public function endingOn(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'ends_on' => $date,
        ]);
    }

    public function withPayee(Payee $payee): static
    {
        return $this->state(fn (array $attributes) => [
            'financial_payee_id' => $payee->id,
        ]);
    }
}
