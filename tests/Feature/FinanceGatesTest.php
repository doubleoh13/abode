<?php

use App\Enums\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Gate;

test('finance gates deny a user without the permission', function (string $ability) {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->denies($ability))->toBeTrue();
})->with(['view-finances', 'manage-finances']);

test('finance gates allow a user granted the permission', function (Permission $permission) {
    $user = User::factory()->create();
    UserPermission::factory()->for($user)->create(['permission' => $permission]);

    expect(Gate::forUser($user)->allows($permission->value))->toBeTrue();
})->with(Permission::cases());

test('a permission grants only its own gate', function () {
    $user = User::factory()->create();
    UserPermission::factory()->for($user)->create(['permission' => Permission::ViewFinances]);

    expect(Gate::forUser($user)->allows('view-finances'))->toBeTrue()
        ->and(Gate::forUser($user)->denies('manage-finances'))->toBeTrue();
});
