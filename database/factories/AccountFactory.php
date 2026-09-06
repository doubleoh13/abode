<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_type' => AccountType::Expense,
            'institution_id' => null,
            'parent_id' => null,
            'name' => fake()->unique()->word(),
            'opened_at' => null,
            'closed_at' => null,
        ];
    }

    public function ofType(AccountType $accountType): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => $accountType,
        ]);
    }

    public function childOf(Account $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
            'account_type' => $parent->account_type,
        ]);
    }

    public function atInstitution(Institution $institution): static
    {
        return $this->state(fn (array $attributes) => [
            'institution_id' => $institution->id,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'opened_at' => fake()->dateTimeBetween('-10 years', '-2 years'),
            'closed_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }
}
