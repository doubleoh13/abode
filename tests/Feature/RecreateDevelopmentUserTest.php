<?php

use App\Models\User;
use Illuminate\Database\Events\DatabaseRefreshed;
use Laravel\Sanctum\PersonalAccessToken;

test('a database refresh recreates the development user and token in the local environment', function () {
    $this->app['env'] = 'local';
    config()->set('development.api_token', 'plain-text-token');

    event(new DatabaseRefreshed);

    $user = User::query()->where('email', 'jake@jakerichhart.com')->sole();
    $token = PersonalAccessToken::findToken('plain-text-token');

    expect($token)->not->toBeNull()
        ->and($token->tokenable->is($user))->toBeTrue()
        ->and($token->abilities)->toBe(['*']);
});

test('the development user is recreated without a token when none is configured', function () {
    $this->app['env'] = 'local';
    config()->set('development.api_token', null);

    event(new DatabaseRefreshed);

    $user = User::query()->where('email', 'jake@jakerichhart.com')->sole();

    expect($user->tokens()->count())->toBe(0);
});

test('a database refresh outside the local environment creates nothing', function () {
    event(new DatabaseRefreshed);

    expect(User::query()->where('email', 'jake@jakerichhart.com')->exists())->toBeFalse();
});
