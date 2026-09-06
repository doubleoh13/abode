<?php

use App\Models\Financial\CommodityPrice;
use Illuminate\Database\QueryException;

test('a price point records created_at and never an updated_at', function () {
    $price = CommodityPrice::factory()->create();

    expect($price->created_at)->not->toBeNull()
        ->and($price->getAttributes())->not->toHaveKey('updated_at');
});

test('duplicate points for the same commodity and instant are rejected', function () {
    $price = CommodityPrice::factory()->create();

    expect(fn () => CommodityPrice::factory()->create([
        'commodity_id' => $price->commodity_id,
        'priced_at' => $price->priced_at,
    ]))->toThrow(QueryException::class);
});
