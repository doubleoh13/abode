<?php

use App\Enums\Permission;
use App\Models\User;
use App\Models\UserPermission;
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
