<?php

use App\Enums\Permission;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/commodities')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/commodities', [
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

    test('required commodity fields use form language', function () {
        $this->postJson('/api/v1/financial/commodities', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Enter a commodity code.')
            ->assertJsonPath('errors.name.0', 'Enter a commodity name.')
            ->assertJsonPath('errors.kind.0', 'Choose a commodity kind.')
            ->assertJsonPath('errors.precision.0', 'Enter the commodity precision.');
    });

    test('USD is present after migrating', function () {
        $this->getJson('/api/v1/financial/commodities')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'USD')
            ->assertJsonPath('data.0.precision', 2)
            ->assertJsonPath('data.0.symbol_placement', 'prefix');
    });

    test('a currency commodity can be created with display hints', function () {
        $this->postJson('/api/v1/financial/commodities', [
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

    test('precision above the commodity scale cap is rejected', function () {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'ETH',
            'name' => 'Ether',
            'kind' => 'traded',
            'precision' => 256,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.precision.0', 'Precision cannot exceed 255 decimal places.');
    });

    test('precision supports the full unsigned tiny integer range', function () {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'ATOM',
            'name' => 'Atomic Unit',
            'kind' => 'traded',
            'precision' => 255,
        ])->assertCreated()
            ->assertJsonPath('data.precision', 255);
    });

    test('a symbol requires a placement and vice versa', function (array $payload, string $errorField) {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'EUR',
            'name' => 'Euro',
            'kind' => 'currency',
            'precision' => 2,
            ...$payload,
        ])->assertUnprocessable()
            ->assertJsonPath("errors.{$errorField}.0", $errorField === 'symbol'
                ? 'Enter a symbol when choosing its placement.'
                : 'Choose where the symbol appears.');
    })->with([
        'symbol only' => [['symbol' => '$'], 'symbol_placement'],
        'placement only' => [['symbol_placement' => 'prefix'], 'symbol'],
    ]);

    test('duplicate codes are rejected', function () {
        Commodity::factory()->create(['code' => 'FBTC']);

        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'FBTC',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => 'traded',
            'precision' => 8,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This commodity code is already in use.');
    });

    test('updating a commodity keeps its own code available', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'precision' => 8]);

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => 'traded',
            'precision' => 8,
        ])->assertOk();
    });

    test('a commodity can be deleted', function () {
        $commodity = Commodity::factory()->create();

        $this->deleteJson("/api/v1/financial/commodities/{$commodity->id}")->assertNoContent();

        $this->assertModelMissing($commodity);
    });

    test('deleting a commodity with journal postings conflicts', function () {
        $commodity = Commodity::factory()->create();
        Posting::factory()->ofCommodity($commodity)->create();

        $this->deleteJson("/api/v1/financial/commodities/{$commodity->id}")->assertConflict();
    });

    test('precision cannot decrease while postings reference the commodity', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'precision' => 8]);
        Posting::factory()->ofCommodity($commodity)->create();

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => $commodity->name,
            'kind' => 'traded',
            'precision' => 6,
        ])->assertUnprocessable()->assertJsonValidationErrors('precision');
    });

    test('increasing precision rescales existing posting amounts', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'precision' => 4]);
        $posting = Posting::factory()->ofCommodity($commodity)->create(['amount' => 12_345]);

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => $commodity->name,
            'kind' => 'traded',
            'precision' => 6,
        ])->assertOk();

        expect((string) $posting->refresh()->amount)->toBe('1234500');
    });

    test('precision cannot increase when rescaling would exceed atomic storage', function () {
        $commodity = Commodity::factory()->create(['code' => 'MAX', 'precision' => 0]);
        Posting::factory()->ofCommodity($commodity)->create(['amount' => str_repeat('9', 78)]);

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'MAX',
            'name' => $commodity->name,
            'kind' => 'traded',
            'precision' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.precision.0', 'Precision cannot increase because a stored value would exceed 78 digits.');
    });

    test('increasing the base currency precision also rescales lot costs', function () {
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $posting = Posting::factory()->ofCommodity($usd)->create(['amount' => 500]);
        $lot = Lot::factory()->create(['cost' => 425_000]);

        $this->putJson("/api/v1/financial/commodities/{$usd->id}", [
            'code' => 'USD',
            'name' => $usd->name,
            'kind' => 'currency',
            'precision' => 3,
            'symbol' => $usd->symbol,
            'symbol_placement' => $usd->symbol_placement?->value,
        ])->assertOk();

        expect((string) $posting->refresh()->amount)->toBe('5000')
            ->and((string) $lot->refresh()->cost)->toBe('4250000');
    });
});
