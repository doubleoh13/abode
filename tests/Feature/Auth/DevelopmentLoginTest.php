<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

test('the bypass does not exist outside the local environment', function () {
    User::factory()->create(['email' => config('development.user_email')]);

    $this->postJson('/dev/login')->assertNotFound();

    $this->assertGuest();
});

test('the bypass logs in the development user locally', function () {
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);
    $user = User::factory()->create(['email' => config('development.user_email')]);

    $this->postJson('/dev/login')->assertNoContent();

    $this->assertAuthenticatedAs($user);
});
