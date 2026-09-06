<?php

use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use App\Models\Financial\Institution;
use Database\Seeders\DevelopmentFinancialSeeder;

test('the seeder populates the finance domain in the local environment', function () {
    $this->app['env'] = 'local';

    (new DevelopmentFinancialSeeder)->run();

    expect(Institution::query()->count())->toBeGreaterThan(0)
        ->and(Account::query()->count())->toBeGreaterThan(0)
        ->and(Commodity::query()->where('code', 'FBTC')->exists())->toBeTrue()
        ->and(CommodityPrice::query()->count())->toBeGreaterThan(90);
});

test('the seeder is idempotent', function () {
    $this->app['env'] = 'local';

    (new DevelopmentFinancialSeeder)->run();
    $accountCount = Account::query()->count();

    (new DevelopmentFinancialSeeder)->run();

    expect(Account::query()->count())->toBe($accountCount);
});

test('the seeder does nothing outside the local environment', function () {
    (new DevelopmentFinancialSeeder)->run();

    expect(Account::query()->exists())->toBeFalse();
});
