<?php

use App\Enums\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

test('the authenticated user is returned with their permissions', function () {
    $user = User::factory()->create();
    UserPermission::factory()->for($user)->create(['permission' => Permission::ViewFinances]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.permissions', ['view-finances']);
});

test('a user without grants has an empty permissions list', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.permissions', []);
});

test('guests receive a 401', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

test('guests without a json accept header still receive a 401', function () {
    $this->get('/api/v1/user')->assertUnauthorized();
});

test('guests cannot update the profile or password', function () {
    $this->patchJson('/api/v1/user', ['name' => 'Someone', 'email' => 'someone@example.com'])
        ->assertUnauthorized();

    $this->putJson('/api/v1/user/password', [])->assertUnauthorized();
});

test('the profile is updated and returned with permissions', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
    UserPermission::factory()->for($user)->create(['permission' => Permission::ViewFinances]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/user', ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.permissions', ['view-finances']);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new@example.com']);
});

test('the profile keeps its own email address', function () {
    $user = User::factory()->create(['email' => 'jake@example.com']);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/user', ['name' => 'Jake Richhart', 'email' => 'jake@example.com'])
        ->assertOk();
});

test('the profile rejects an email address another user has', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson('/api/v1/user', ['name' => 'Jake Richhart', 'email' => 'taken@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'That email address is already in use.');
});

test('the password is changed', function () {
    $user = User::factory()->create(['password' => 'the-old-password']);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/password', [
        'current_password' => 'the-old-password',
        'password' => 'the-new-password',
        'password_confirmation' => 'the-new-password',
    ])->assertNoContent();

    expect(Hash::check('the-new-password', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('the-old-password', $user->fresh()->password))->toBeFalse();
});

test('the password is unchanged when the current password is wrong', function () {
    $user = User::factory()->create(['password' => 'the-old-password']);

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/user/password', [
        'current_password' => 'not-the-password',
        'password' => 'the-new-password',
        'password_confirmation' => 'the-new-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'That does not match your current password.');

    expect(Hash::check('the-old-password', $user->fresh()->password))->toBeTrue();
});

test('the password requires a matching confirmation', function () {
    Sanctum::actingAs(User::factory()->create(['password' => 'the-old-password']));

    $this->putJson('/api/v1/user/password', [
        'current_password' => 'the-old-password',
        'password' => 'the-new-password',
        'password_confirmation' => 'something-else',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Enter the same password in both fields.');
});
