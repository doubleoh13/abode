<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('credentials are exchanged for a token that authenticates requests', function () {
    $user = User::factory()->create(['email' => 'jake@example.com', 'password' => 'correct-horse']);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'Jake@Example.com',
        'password' => 'correct-horse',
        'device_name' => 'Pixel',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Pixel');

    $plainTextToken = $response->json('plain_text_token');

    expect(PersonalAccessToken::findToken($plainTextToken)->tokenable->is($user))->toBeTrue();

    $this->withToken($plainTextToken)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.email', 'jake@example.com');
});

test('a wrong password is rejected without revealing which field failed', function () {
    User::factory()->create(['email' => 'jake@example.com', 'password' => 'correct-horse']);

    $this->postJson('/api/v1/login', [
        'email' => 'jake@example.com',
        'password' => 'wrong',
        'device_name' => 'Pixel',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email or password is incorrect.');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('an unknown email is rejected the same way', function () {
    $this->postJson('/api/v1/login', [
        'email' => 'nobody@example.com',
        'password' => 'anything',
        'device_name' => 'Pixel',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email or password is incorrect.');
});

test('a device name is required', function () {
    User::factory()->create(['email' => 'jake@example.com', 'password' => 'correct-horse']);

    $this->postJson('/api/v1/login', [
        'email' => 'jake@example.com',
        'password' => 'correct-horse',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('device_name');
});

test('login attempts are throttled per email and address', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/login', ['email' => 'jake@example.com', 'password' => 'wrong', 'device_name' => 'Pixel'])
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/login', ['email' => 'jake@example.com', 'password' => 'wrong', 'device_name' => 'Pixel'])
        ->assertTooManyRequests();
});

test('logout revokes only the token used for the request', function () {
    $user = User::factory()->create();
    $phone = $user->createToken('Pixel')->plainTextToken;
    $laptop = $user->createToken('Laptop')->plainTextToken;

    $this->withToken($phone)->postJson('/api/v1/logout')->assertNoContent();

    expect(PersonalAccessToken::findToken($phone))->toBeNull()
        ->and(PersonalAccessToken::findToken($laptop))->not->toBeNull();
});

test('logout conflicts for a session login', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'http://localhost')
        ->postJson('/api/v1/logout')
        ->assertConflict();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
