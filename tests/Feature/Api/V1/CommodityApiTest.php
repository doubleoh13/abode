<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/commodities')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/commodities', [
        'code' => 'USD',
        'name' => 'US Dollar',
        'kind' => 'currency',
        'display_precision' => 2,
    ])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('a fetchable price source requires its quote symbol', function () {
        $payload = [
            'code' => 'VTI',
            'name' => 'Vanguard Total Stock Market ETF',
            'kind' => 'traded',
            'display_precision' => 4,
        ];

        $this->postJson('/api/v1/financial/commodities', [...$payload, 'price_source' => 'yahoo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_symbol' => 'Enter the quote symbol for this price source.']);

        $this->postJson('/api/v1/financial/commodities', [...$payload, 'price_source' => 'manual', 'price_symbol' => 'VTI'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_symbol' => 'A quote symbol only applies to fetchable price sources.']);

        $this->postJson('/api/v1/financial/commodities', [...$payload, 'price_source' => 'yahoo', 'price_symbol' => 'VTI'])
            ->assertCreated()
            ->assertJsonPath('data.price_source', 'yahoo')
            ->assertJsonPath('data.price_symbol', 'VTI');
    });

    test('balances sum the commodity postings per account', function () {
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);
        $brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        $trezor = Account::factory()->ofType(AccountType::Asset)->create();
        $transaction = Transaction::factory()->on('2026-01-05')->create();
        Posting::factory()->forTransaction($transaction, 0)->inAccount($brokerage)->ofCommodity($fbtc)->create(['amount' => '2']);
        Posting::factory()->forTransaction($transaction, 1)->inAccount($trezor)->ofCommodity($fbtc)->create(['amount' => '0.5']);

        $this->getJson("/api/v1/financial/commodities/{$fbtc->id}/balances")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.financial_account_id', $brokerage->id)
            ->assertJsonPath('data.0.balance', '2')
            ->assertJsonPath('data.1.balance', '0.5');
    });

    test('required commodity fields use form language', function () {
        $this->postJson('/api/v1/financial/commodities', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Enter a commodity code.')
            ->assertJsonPath('errors.name.0', 'Enter a commodity name.')
            ->assertJsonPath('errors.kind.0', 'Choose a commodity kind.')
            ->assertJsonPath('errors.display_precision.0', 'Enter the display precision.');
    });

    test('USD is present after migrating', function () {
        $this->getJson('/api/v1/financial/commodities')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'USD')
            ->assertJsonPath('data.0.display_precision', 2)
            ->assertJsonPath('data.0.symbol_placement', 'prefix');
    });

    test('a currency commodity can be created with display hints', function () {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'EUR',
            'name' => 'Euro',
            'kind' => 'currency',
            'display_precision' => 2,
            'symbol' => '€',
            'symbol_placement' => 'prefix',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'EUR')
            ->assertJsonPath('data.symbol_placement', 'prefix');
    });

    test('display precision above the display cap is rejected', function () {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'ETH',
            'name' => 'Ether',
            'kind' => 'traded',
            'display_precision' => 26,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.display_precision.0', 'Display precision cannot exceed 25 decimal places.');
    });

    test('display precision supports all stored fractional places', function () {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'ATOM',
            'name' => 'Atomic Unit',
            'kind' => 'traded',
            'display_precision' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.display_precision', 25);
    });

    test('a symbol requires a placement and vice versa', function (array $payload, string $errorField) {
        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'EUR',
            'name' => 'Euro',
            'kind' => 'currency',
            'display_precision' => 2,
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
            'display_precision' => 8,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This commodity code is already in use.');
    });

    test('codes are normalized to uppercase, making uniqueness case-insensitive', function () {
        Commodity::factory()->create(['code' => 'FBTC']);

        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'fbtc',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => 'traded',
            'display_precision' => 8,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This commodity code is already in use.');

        $this->postJson('/api/v1/financial/commodities', [
            'code' => 'spaxx',
            'name' => 'Fidelity Government Money Market Fund',
            'kind' => 'traded',
            'display_precision' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'SPAXX');
    });

    test('updating a commodity keeps its own code available', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'display_precision' => 8]);

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => 'traded',
            'display_precision' => 8,
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

    test('display precision can decrease while postings reference the commodity', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'display_precision' => 8]);
        Posting::factory()->ofCommodity($commodity)->create();

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => $commodity->name,
            'kind' => 'traded',
            'display_precision' => 6,
        ])->assertOk()->assertJsonPath('data.display_precision', 6);
    });

    test('increasing display precision preserves existing posting amounts', function () {
        $commodity = Commodity::factory()->create(['code' => 'FBTC', 'display_precision' => 4]);
        $posting = Posting::factory()->ofCommodity($commodity)->create(['amount' => 12_345]);

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'FBTC',
            'name' => $commodity->name,
            'kind' => 'traded',
            'display_precision' => 6,
        ])->assertOk();

        expect((string) $posting->refresh()->amount)->toBe('12345');
    });

    test('display precision can increase for maximum stored amounts', function () {
        $commodity = Commodity::factory()->create(['code' => 'MAX', 'display_precision' => 0]);
        Posting::factory()->ofCommodity($commodity)->create(['amount' => str_repeat('9', 53)]);

        $this->putJson("/api/v1/financial/commodities/{$commodity->id}", [
            'code' => 'MAX',
            'name' => $commodity->name,
            'kind' => 'traded',
            'display_precision' => 1,
        ])->assertOk()->assertJsonPath('data.display_precision', 1);
    });

    test('changing base currency display precision preserves postings and lot costs', function () {
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $posting = Posting::factory()->ofCommodity($usd)->create(['amount' => 500]);
        $lot = Lot::factory()->create(['cost' => 425_000]);

        $this->putJson("/api/v1/financial/commodities/{$usd->id}", [
            'code' => 'USD',
            'name' => $usd->name,
            'kind' => 'currency',
            'display_precision' => 3,
            'symbol' => $usd->symbol,
            'symbol_placement' => $usd->symbol_placement?->value,
        ])->assertOk();

        expect((string) $posting->refresh()->amount)->toBe('500')
            ->and((string) $lot->refresh()->cost)->toBe('425000');
    });
});
