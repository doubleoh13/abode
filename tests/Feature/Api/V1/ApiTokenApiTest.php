<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/tokens')->assertUnauthorized();
});

test('a token is created with its plain text returned once', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/tokens', ['name' => 'Native app'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Native app')
        ->assertJsonPath('data.last_used_at', null);

    $plainTextToken = $response->json('plain_text_token');
    $token = PersonalAccessToken::findToken($plainTextToken);

    expect($token->tokenable->is($user))->toBeTrue()
        ->and($token->abilities)->toBe(['*'])
        ->and($token->expires_at)->toBeNull();
});

test('a created token authenticates api requests', function () {
    $user = User::factory()->create();
    $plainTextToken = $user->createToken('Native app')->plainTextToken;

    $this->withToken($plainTextToken)
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

test('only the current user\'s tokens are listed, newest first', function () {
    $user = User::factory()->create();
    $user->createToken('Older');
    $user->createToken('Newer');
    User::factory()->create()->createToken('Someone else');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tokens')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Newer')
        ->assertJsonPath('data.1.name', 'Older');
});

test('a token is revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Native app')->accessToken;

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/tokens/{$token->id}")->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
});

test('another user\'s token cannot be revoked', function () {
    $token = User::factory()->create()->createToken('Theirs')->accessToken;

    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/v1/tokens/{$token->id}")->assertNotFound();

    $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
});

test('a token requires a name', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/tokens', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Name the token so you can recognize it later.');
});
