<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('the authenticated user is returned', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('email', $user->email);
});

test('guests receive a 401', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});
