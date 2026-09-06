<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('a user can log in with valid credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('logging in with remember issues a remember cookie', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $response->assertOk()->assertCookie(Auth::guard('web')->getRecallerName());
});

test('invalid credentials are rejected', function () {
    $user = User::factory()->create();

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->assertGuest();
});

test('login attempts are rate limited', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});

test('an authenticated user can log out', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson('/logout')->assertNoContent();

    $this->assertGuest();
});
