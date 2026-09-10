<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function setupPayload(): array
{
    return [
        'name' => 'Jake Richhart',
        'email' => 'jake@example.com',
        'password' => 'a-strong-password',
        'password_confirmation' => 'a-strong-password',
    ];
}

// A duplicate-email 422 is unreachable: any existing user makes the endpoint 404 first.
test('the setup surface does not exist once a user exists', function () {
    User::factory()->create();

    $this->postJson('/api/v1/setup', setupPayload())->assertNotFound();

    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
});

test('setup is reported as required on a fresh instance', function () {
    $this->getJson('/api/v1/setup')
        ->assertOk()
        ->assertJsonPath('data.required', true);
});

test('setup is reported as not required once a user exists', function () {
    User::factory()->create();

    $this->getJson('/api/v1/setup')
        ->assertOk()
        ->assertJsonPath('data.required', false);
});

test('setup creates the first user with full permissions and an authenticated session', function () {
    $this->postJson('/api/v1/setup', setupPayload(), ['Referer' => 'http://localhost'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'jake@example.com')
        ->assertJsonPath('data.name', 'Jake Richhart');

    $user = User::sole();
    $this->assertDatabaseHas('user_permissions', ['user_id' => $user->id, 'permission' => 'view-finances']);
    $this->assertDatabaseHas('user_permissions', ['user_id' => $user->id, 'permission' => 'manage-finances']);
    $this->assertAuthenticatedAs($user);
    expect(Hash::check('a-strong-password', $user->password))->toBeTrue();
});

test('setup without a same-origin referer creates the user but starts no session', function () {
    $this->postJson('/api/v1/setup', setupPayload())
        ->assertCreated()
        ->assertJsonPath('data.email', 'jake@example.com');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
});

test('setup rejects an empty submission with form-language errors', function () {
    $this->postJson('/api/v1/setup', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password'])
        ->assertJsonPath('errors.name.0', 'Enter your name.');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

test('setup rejects a mismatched password confirmation', function () {
    $this->postJson('/api/v1/setup', [...setupPayload(), 'password_confirmation' => 'different'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password'])
        ->assertJsonPath('errors.password.0', 'Enter the same password in both fields.');

    $this->assertDatabaseCount('users', 0);
});

test('setup attempts are rate limited', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/setup', [])->assertUnprocessable();
    }

    $this->postJson('/api/v1/setup', setupPayload(), ['Referer' => 'http://localhost'])
        ->assertTooManyRequests();

    $this->assertDatabaseCount('users', 0);
});
