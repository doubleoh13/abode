<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

function lotPickerPosting(Lot $lot, Account $account, int $amount, string $date): Posting
{
    $transaction = Transaction::factory()->on($date)->create();

    return Posting::factory()
        ->forTransaction($transaction, 0)
        ->inAccount($account)
        ->withLot($lot)
        ->create(['amount' => $amount]);
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/lots')->assertUnauthorized();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances);

        $this->brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        $this->fbtc = Commodity::factory()->create(['precision' => 8]);
    });

    test('the account and commodity filters are required', function () {
        $this->getJson('/api/v1/financial/lots')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_account_id', 'financial_commodity_id']);
    });

    test('open quantity is derived per account', function () {
        $otherAccount = Account::factory()->ofType(AccountType::Asset)->create();
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create();
        lotPickerPosting($lot, $this->brokerage, 100, '2026-01-05');
        lotPickerPosting($lot, $this->brokerage, -30, '2026-02-01');
        lotPickerPosting($lot, $otherAccount, 30, '2026-02-01');

        $this->getJson("/api/v1/financial/lots?financial_account_id={$this->brokerage->id}&financial_commodity_id={$this->fbtc->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $lot->id)
            ->assertJsonPath('data.0.open_quantity', '70')
            ->assertJsonPath('data.0.acquired_quantity', '130');

        $this->getJson("/api/v1/financial/lots?financial_account_id={$otherAccount->id}&financial_commodity_id={$this->fbtc->id}")
            ->assertOk()
            ->assertJsonPath('data.0.open_quantity', '30');
    });

    test('as_of excludes postings dated after it', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create();
        lotPickerPosting($lot, $this->brokerage, 100, '2026-01-05');
        lotPickerPosting($lot, $this->brokerage, -40, '2026-06-01');

        $this->getJson("/api/v1/financial/lots?financial_account_id={$this->brokerage->id}&financial_commodity_id={$this->fbtc->id}&as_of=2026-03-01")
            ->assertOk()
            ->assertJsonPath('data.0.open_quantity', '100');

        $this->getJson("/api/v1/financial/lots?financial_account_id={$this->brokerage->id}&financial_commodity_id={$this->fbtc->id}")
            ->assertOk()
            ->assertJsonPath('data.0.open_quantity', '60');
    });

    test('fully consumed lots are excluded', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create();
        lotPickerPosting($lot, $this->brokerage, 100, '2026-01-05');
        lotPickerPosting($lot, $this->brokerage, -100, '2026-06-01');

        $this->getJson("/api/v1/financial/lots?financial_account_id={$this->brokerage->id}&financial_commodity_id={$this->fbtc->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});
