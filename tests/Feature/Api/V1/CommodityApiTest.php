<?php

use App\Enums\Permission;
use App\Models\Commodity;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/commodities')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/commodities', [
        'code' => 'USD',
        'name' => 'US Dollar',
        'kind' => 'currency',
        'precision' => 2,
    ])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('USD is present after migrating', function () {
        $this->getJson('/api/v1/commodities')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'USD')
            ->assertJsonPath('data.0.precision', 2)
            ->assertJsonPath('data.0.symbol_placement', 'prefix');
    });

    test('a currency commodity can be created with display hints', function () {
        $this->postJson('/api/v1/commodities', [
            'code' => 'EUR',
            'name' => 'Euro',
            'kind' => 'currency',
            'precision' => 2,
            'symbol' => '€',
            'symbol_placement' => 'prefix',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'EUR')
            ->assertJsonPath('data.symbol_placement', 'prefix');
    });

    test('precision above the storage cap is rejected', function () {
        $this->postJson('/api/v1/commodities', [
            'code' => 'ETH',
            'name' => 'Ether',
            'kind' => 'traded',
            'precision' => 18,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('precision');
    });

    test('a symbol requires a placement and vice versa', function (array $payload, string $errorField) {
        $this->postJson('/api/v1/commodities', [
            'code' => 'EUR',
            'name' => 'Euro',
            'kind' => 'currency',
            'precision' => 2,
            ...$payload,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors($errorField);
    })->with([
        'symbol only' => [['symbol' => '$'], 'symbol_placement'],
        'placement only' => [['symbol_placement' => 'prefix'], 'symbol'],
    ]);

    test('duplicate codes are rejected', function () {
        Commodity::factory()->create(['code' => 'FBTC']);

        $this->postJson('/api/v1/commodities', [
            'code' => 'FBTC',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => 'traded',
            'precision' => 8,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    });

    test('updating a commodity keeps its own code available', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'precision' => 8]);

        $this->putJson("/api/v1/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => 'traded',
            'precision' => 8,
        ])->assertOk();
    });

    test('a commodity can be deleted', function () {
        $commodity = Commodity::factory()->create();

        $this->deleteJson("/api/v1/commodities/{$commodity->id}")->assertNoContent();

        $this->assertModelMissing($commodity);
    });
});
