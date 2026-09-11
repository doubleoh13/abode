<?php

use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Transaction;
use App\Models\User;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Support\Facades\Hash;

test('a database refresh recreates the development user in the local environment', function () {
    $this->app['env'] = 'local';
    config()->set('development.user_password', 'development-password');

    event(new DatabaseRefreshed);

    $user = User::query()->where('email', 'jake@jakerichhart.com')->sole();

    expect(Hash::check('development-password', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and($user->hasPermission(Permission::ViewFinances))->toBeTrue()
        ->and($user->hasPermission(Permission::ManageFinances))->toBeTrue()
        ->and(Account::query()->exists())->toBeFalse()
        ->and(Transaction::query()->exists())->toBeFalse()
        ->and(Commodity::query()->where('code', 'FBTC')->exists())->toBeFalse();
});

test('a database refresh outside the local environment creates nothing', function () {
    event(new DatabaseRefreshed);

    expect(User::query()->where('email', 'jake@jakerichhart.com')->exists())->toBeFalse();
});
