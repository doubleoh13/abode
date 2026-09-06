<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Financial\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => 'financial.account',
            'attachable_id' => Account::factory(),
            'user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'attachments/'.Str::random(40),
            'name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 5000000),
            'hash' => hash('sha256', Str::random(40)),
        ];
    }
}
