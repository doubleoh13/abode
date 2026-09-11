<?php

use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;

test('confirming deletes every price point for the commodity only', function () {
    $doomed = Commodity::factory()->create(['code' => 'OP']);
    $kept = Commodity::factory()->create(['code' => 'VTI']);
    CommodityPrice::factory()->count(3)->create(['financial_commodity_id' => $doomed->id]);
    CommodityPrice::factory()->create(['financial_commodity_id' => $kept->id]);

    $this->artisan('financial:delete-prices', ['commodity' => 'OP'])
        ->expectsConfirmation('Delete all 3 price point(s) for OP?', 'yes')
        ->expectsOutputToContain('OP: 3 price point(s) deleted')
        ->assertSuccessful();

    expect(CommodityPrice::query()->where('financial_commodity_id', $doomed->id)->count())->toBe(0)
        ->and(CommodityPrice::query()->where('financial_commodity_id', $kept->id)->count())->toBe(1);
});

test('declining leaves the price points in place', function () {
    $commodity = Commodity::factory()->create(['code' => 'OP']);
    CommodityPrice::factory()->count(2)->create(['financial_commodity_id' => $commodity->id]);

    $this->artisan('financial:delete-prices', ['commodity' => 'OP'])
        ->expectsConfirmation('Delete all 2 price point(s) for OP?', 'no')
        ->assertSuccessful();

    expect(CommodityPrice::query()->where('financial_commodity_id', $commodity->id)->count())->toBe(2);
});

test('an unknown commodity code fails', function () {
    $this->artisan('financial:delete-prices', ['commodity' => 'NOPE'])
        ->expectsOutputToContain('NOPE: no such commodity')
        ->assertFailed();
});
