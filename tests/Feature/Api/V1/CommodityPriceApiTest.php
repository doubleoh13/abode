<?php

use App\Enums\Permission;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;

test('guests receive a 401', function () {
    $this->postJson('/api/v1/financial/commodity-prices', [])->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/commodity-prices', [])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);

        $this->fbtc = Commodity::factory()->create(['display_precision' => 8]);
    });

    test('the index lists a commodity\'s points newest first', function () {
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-01T00:00:00Z',
            'price' => '70',
        ]);
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
            'price' => '78.6014',
        ]);
        CommodityPrice::factory()->create();

        $this->getJson("/api/v1/financial/commodity-prices?financial_commodity_id={$this->fbtc->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.price', '78.6014')
            ->assertJsonPath('data.1.price', '70');
    });

    test('the price series returns dated pairs oldest first', function () {
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
            'price' => '78.6014',
        ]);
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-01T00:00:00Z',
            'price' => '70',
        ]);

        $this->getJson("/api/v1/financial/commodities/{$this->fbtc->id}/price-series")
            ->assertOk()
            ->assertExactJson(['data' => [['2026-09-01', '70'], ['2026-09-05', '78.6014']]]);
    });

    test('a price point can be deleted', function () {
        $point = CommodityPrice::factory()->create(['financial_commodity_id' => $this->fbtc->id]);

        $this->deleteJson("/api/v1/financial/commodity-prices/{$point->id}")->assertNoContent();

        expect(CommodityPrice::query()->find($point->id))->toBeNull();
    });

    test('a price point is recorded', function () {
        $this->postJson('/api/v1/financial/commodity-prices', [
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
            'price' => '78.6014',
        ])
            ->assertCreated()
            ->assertJsonPath('data.price', '78.6014');

        expect((string) CommodityPrice::query()->sole()->price)->toBe('78.6014');
    });

    test('a duplicate price point is rejected', function () {
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
        ]);

        $this->postJson('/api/v1/financial/commodity-prices', [
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
            'price' => '80',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'priced_at' => 'A price already exists for this commodity and time.',
            ]);
    });

    test('a negative price is rejected', function () {
        $this->postJson('/api/v1/financial/commodity-prices', [
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
            'price' => '-1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price' => 'Enter a valid price.']);
    });

    test('commodities expose their latest price', function () {
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-01T00:00:00Z',
            'price' => '70',
        ]);
        CommodityPrice::factory()->create([
            'financial_commodity_id' => $this->fbtc->id,
            'priced_at' => '2026-09-05T00:00:00Z',
            'price' => '78.6014',
        ]);

        $this->getJson("/api/v1/financial/commodities/{$this->fbtc->id}")
            ->assertOk()
            ->assertJsonPath('data.latest_price', '78.6014')
            ->assertJsonPath('data.latest_priced_at', '2026-09-05');

        $listed = collect($this->getJson('/api/v1/financial/commodities')->assertOk()->json('data'))
            ->firstWhere('id', $this->fbtc->id);

        expect($listed['latest_price'])->toBe('78.6014');
    });
});
