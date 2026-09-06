<?php

use App\Models\Financial\CommodityPrice;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;

test('a price point records created_at and never an updated_at', function () {
    $price = CommodityPrice::factory()->create();

    expect($price->created_at)->not->toBeNull()
        ->and($price->getAttributes())->not->toHaveKey('updated_at');
});

test('duplicate points for the same commodity and instant are rejected', function () {
    $price = CommodityPrice::factory()->create();

    expect(fn () => CommodityPrice::factory()->create([
        'financial_commodity_id' => $price->financial_commodity_id,
        'priced_at' => $price->priced_at,
    ]))->toThrow(QueryException::class);
});

test('a price is represented as an exact decimal', function () {
    $price = CommodityPrice::factory()->create([
        'price' => '0.000000000000000001234567890123456789',
    ]);

    expect($price->price)->toBeInstanceOf(BigDecimal::class)
        ->and($price->price->isEqualTo('0.000000000000000001234567890123456789'))->toBeTrue();
});
