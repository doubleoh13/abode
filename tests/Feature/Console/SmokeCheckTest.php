<?php

use App\Models\Financial\Account;
use App\Models\User;

test('app:smoke succeeds against a migrated database', function () {
    User::factory()->create();
    Account::factory()->create();

    $this->artisan('app:smoke')->assertSuccessful();
});
