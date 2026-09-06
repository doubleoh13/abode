<?php

use App\Support\Financial\CostBasisBalancer;

describe('allocate', function () {
    test('a sole full acquisition values at exactly the lot cost', function () {
        $allocations = new CostBasisBalancer()->allocate(425_000, 50_000_000, [50_000_000]);

        expect($allocations)->toBe([425_000]);
    });

    test('a sole partial consumption floors the pro-rata share', function () {
        $allocations = new CostBasisBalancer()->allocate(425_000, 50_000_000, [20_000_000]);

        expect($allocations)->toBe([170_000]);
    });

    test('the shortfall is distributed by descending remainder', function () {
        $allocations = new CostBasisBalancer()->allocate(100, 7, [3, 2, 2]);

        expect($allocations)->toBe([43, 29, 28])
            ->and(array_sum($allocations))->toBe(100);
    });

    test('remainder ties break by input order', function () {
        $allocations = new CostBasisBalancer()->allocate(100, 3, [1, 1, 1]);

        expect($allocations)->toBe([34, 33, 33]);
    });

    test('full consumption across several legs sums to exactly the lot cost', function () {
        $allocations = new CostBasisBalancer()->allocate(999, 10, [3, 3, 4]);

        expect(array_sum($allocations))->toBe(999);
    });
});

describe('residual', function () {
    test('a buy balances its cash leg against the new lot at cost', function () {
        $residual = new CostBasisBalancer()->residual([
            ['is_base' => false, 'amount' => 50_000_000, 'lot_key' => 'new-0', 'lot_cost' => 425_000, 'lot_total_quantity' => 50_000_000],
            ['is_base' => true, 'amount' => -425_000, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
        ]);

        expect($residual)->toBe(0);
    });

    test('a sale balances proceeds against allocated basis plus the gains leg', function () {
        $residual = new CostBasisBalancer()->residual([
            ['is_base' => false, 'amount' => -20_000_000, 'lot_key' => 7, 'lot_cost' => 425_000, 'lot_total_quantity' => 50_000_000],
            ['is_base' => true, 'amount' => 190_000, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
            ['is_base' => true, 'amount' => -20_000, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
        ]);

        expect($residual)->toBe(0);
    });

    test('a multi-leg same-lot sale balances only under largest-remainder allocation', function () {
        $residual = new CostBasisBalancer()->residual([
            ['is_base' => false, 'amount' => -3, 'lot_key' => 1, 'lot_cost' => 100, 'lot_total_quantity' => 7],
            ['is_base' => false, 'amount' => -2, 'lot_key' => 1, 'lot_cost' => 100, 'lot_total_quantity' => 7],
            ['is_base' => false, 'amount' => -2, 'lot_key' => 1, 'lot_cost' => 100, 'lot_total_quantity' => 7],
            ['is_base' => true, 'amount' => 100, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
        ]);

        expect($residual)->toBe(0);
    });

    test('an unbalanced transaction reports its signed residual', function () {
        $residual = new CostBasisBalancer()->residual([
            ['is_base' => true, 'amount' => 190_000, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
            ['is_base' => true, 'amount' => -190_001, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
        ]);

        expect($residual)->toBe(-1);
    });

    test('a lot-bearing leg without a usable lot contributes nothing', function () {
        $residual = new CostBasisBalancer()->residual([
            ['is_base' => false, 'amount' => 500, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
            ['is_base' => false, 'amount' => -300, 'lot_key' => 9, 'lot_cost' => 100, 'lot_total_quantity' => 0],
            ['is_base' => true, 'amount' => -100, 'lot_key' => null, 'lot_cost' => null, 'lot_total_quantity' => null],
        ]);

        expect($residual)->toBe(-100);
    });
});
