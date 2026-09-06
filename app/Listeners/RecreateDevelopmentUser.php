<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Support\Str;

class RecreateDevelopmentUser
{
    public function handle(DatabaseRefreshed $event): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $user = User::query()->create([
            'name' => 'Jake Richhart',
            'email' => 'jake@jakerichhart.com',
            'password' => config('development.user_password') ?? Str::password(),
        ]);

        $plainTextToken = config('development.api_token');

        if ($plainTextToken !== null) {
            $user->tokens()->create([
                'name' => 'development',
                'token' => hash('sha256', $plainTextToken),
                'abilities' => ['*'],
            ]);
        }
    }
}
