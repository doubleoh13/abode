<?php

use App\Models\Financial\CommodityPrice;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
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
        'price' => '0.0000000000000000012345678',
    ]);

    expect($price->price)->toBeInstanceOf(BigDecimal::class)
        ->and($price->price->isEqualTo('0.0000000000000000012345678'))->toBeTrue();
});

test('a negative price is rejected by the database', function () {
    expect(fn () => CommodityPrice::factory()->create(['price' => '-1']))
        ->toThrow(QueryException::class, 'financial_commodity_prices_price_nonnegative');
});

test('a price exceeding storage scale is rejected before the database can round it', function () {
    expect(fn () => CommodityPrice::factory()->create(['price' => '0.'.str_repeat('0', 25).'1']))
        ->toThrow(RoundingNecessaryException::class);
});
