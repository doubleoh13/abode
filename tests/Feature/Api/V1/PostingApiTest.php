<?php

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

function postingIn(AccountType $accountType, ?PostingStatus $status): Posting
{
    return Posting::factory()
        ->forTransaction(Transaction::factory()->on('2026-01-05')->create(), 0)
        ->inAccount(Account::factory()->ofType($accountType)->create())
        ->ofCommodity(Commodity::query()->where('code', 'USD')->firstOrFail())
        ->create(['amount' => 100, 'status' => $status]);
}

test('guests receive a 401', function () {
    $posting = postingIn(AccountType::Asset, PostingStatus::Pending);

    $this->patchJson("/api/v1/financial/postings/{$posting->id}", ['status' => 'cleared'])
        ->assertUnauthorized();
    $this->getJson('/api/v1/financial/postings?financial_account_id=1')->assertUnauthorized();
});

describe('with view permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances);
    });

    test('the register requires an account or a commodity', function () {
        $this->getJson('/api/v1/financial/postings')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_account_id', 'financial_commodity_id']);
    });

    test('a commodity register spans accounts with a global running quantity', function () {
        $brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        $trezor = Account::factory()->ofType(AccountType::Asset)->create();
        $checking = Account::factory()->ofType(AccountType::Asset)->create();
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();

        foreach ([[$brokerage, '2026-01-05', '2'], [$trezor, '2026-01-10', '1'], [$brokerage, '2026-01-20', '-0.5']] as [$account, $date, $amount]) {
            $transaction = Transaction::factory()->on($date)->create();
            Posting::factory()->forTransaction($transaction, 0)->inAccount($account)->ofCommodity($fbtc)
                ->create(['amount' => $amount, 'status' => 'cleared']);
            Posting::factory()->forTransaction($transaction, 1)->inAccount($checking)->ofCommodity($usd)
                ->create(['amount' => '1', 'status' => 'cleared']);
        }

        $this->getJson("/api/v1/financial/postings?financial_commodity_id={$fbtc->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.running_balance', '2.5')
            ->assertJsonPath('data.1.running_balance', '3')
            ->assertJsonPath('data.2.running_balance', '2')
            ->assertJsonPath('data.0.account.id', $brokerage->id);
    });

    test('a date window restarts the running balance at its start', function () {
        $electricity = Account::factory()->ofType(AccountType::Expense)->create();
        $checking = Account::factory()->ofType(AccountType::Asset)->create();
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();

        foreach ([['2025-12-15', '80'], ['2026-01-10', '100'], ['2026-02-10', '120'], ['2027-01-05', '90']] as [$date, $amount]) {
            $transaction = Transaction::factory()->on($date)->create();
            Posting::factory()->forTransaction($transaction, 0)->inAccount($electricity)->ofCommodity($usd)->create(['amount' => $amount]);
            Posting::factory()->forTransaction($transaction, 1)->inAccount($checking)->ofCommodity($usd)->create(['amount' => bcmul($amount, '-1', 2)]);
        }

        $this->getJson("/api/v1/financial/postings?financial_account_id={$electricity->id}&from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.transaction.date', '2026-02-10')
            ->assertJsonPath('data.0.running_balance', '220')
            ->assertJsonPath('data.1.running_balance', '100');
    });

    test('include_descendants widens the register to the subtree with one running balance', function () {
        $taxes = Account::factory()->ofType(AccountType::Expense)->create(['name' => 'Taxes']);
        $lastYear = Account::factory()->childOf($taxes)->create(['name' => 'TY2025']);
        $thisYear = Account::factory()->childOf($taxes)->create(['name' => 'TY2026']);
        $checking = Account::factory()->ofType(AccountType::Asset)->create();
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();

        foreach ([['2026-01-05', '100', $lastYear], ['2026-01-10', '50', $thisYear]] as [$date, $amount, $account]) {
            $transaction = Transaction::factory()->on($date)->create();
            Posting::factory()->forTransaction($transaction, 0)->inAccount($account)->ofCommodity($usd)->create(['amount' => $amount]);
            Posting::factory()->forTransaction($transaction, 1)->inAccount($checking)->ofCommodity($usd)->create(['amount' => bcmul($amount, '-1', 2)]);
        }

        $this->getJson("/api/v1/financial/postings?financial_account_id={$taxes->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/financial/postings?financial_account_id={$taxes->id}&include_descendants=1")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.account.id', $thisYear->id)
            ->assertJsonPath('data.0.running_balance', '150')
            ->assertJsonPath('data.1.account.id', $lastYear->id)
            ->assertJsonPath('data.1.running_balance', '100');
    });

    test('the register lists an account\'s postings newest first with running balances', function () {
        $checking = Account::factory()->ofType(AccountType::Asset)->create();
        $groceries = Account::factory()->ofType(AccountType::Expense)->create();
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();

        foreach ([['2026-01-05', '100'], ['2026-01-10', '-30'], ['2026-01-20', '-20.50']] as [$date, $amount]) {
            $transaction = Transaction::factory()->on($date)->create();
            Posting::factory()->forTransaction($transaction, 0)->inAccount($checking)->ofCommodity($usd)
                ->create(['amount' => $amount, 'status' => 'cleared']);
            Posting::factory()->forTransaction($transaction, 1)->inAccount($groceries)->ofCommodity($usd)
                ->create(['amount' => bcmul($amount, '-1', 2), 'status' => null]);
        }

        $newest = Posting::query()->where('financial_account_id', $checking->id)->orderByDesc('id')->firstOrFail();
        $bankRow = BankTransaction::factory()->linkedTo($newest)->create(['posted_on' => '2026-01-21']);

        $this->getJson("/api/v1/financial/postings?financial_account_id={$checking->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount', '-20.5')
            ->assertJsonPath('data.0.running_balance', '49.5')
            ->assertJsonPath('data.1.running_balance', '70')
            ->assertJsonPath('data.2.running_balance', '100')
            ->assertJsonPath('data.0.transaction.date', '2026-01-20')
            ->assertJsonPath('data.0.bank_transaction.id', $bankRow->id)
            ->assertJsonPath('data.0.bank_transaction.posted_on', '2026-01-21')
            ->assertJsonPath('data.1.bank_transaction', null);

        Posting::query()->where('financial_account_id', $checking->id)->orderBy('id')->limit(2)->update(['status' => 'reconciled']);

        $this->getJson("/api/v1/financial/postings?financial_account_id={$checking->id}&hide_reconciled=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '-20.5')
            ->assertJsonPath('data.0.running_balance', '49.5')
            ->assertJsonPath('meta.total', 1);
    });
});

test('viewing permissions cannot update a posting', function () {
    actingWithPermissions(Permission::ViewFinances);
    $posting = postingIn(AccountType::Asset, PostingStatus::Pending);

    $this->patchJson("/api/v1/financial/postings/{$posting->id}", ['status' => 'cleared'])
        ->assertForbidden();
});

describe('with manage permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('an asset posting status is updated', function () {
        $posting = postingIn(AccountType::Asset, PostingStatus::Pending);

        $this->patchJson("/api/v1/financial/postings/{$posting->id}", ['status' => 'reconciled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');

        expect($posting->refresh()->status)->toBe(PostingStatus::Reconciled);
    });

    test('a status submitted for an income posting is stored as null', function () {
        $posting = postingIn(AccountType::Income, null);

        $this->patchJson("/api/v1/financial/postings/{$posting->id}", ['status' => 'cleared'])
            ->assertOk()
            ->assertJsonPath('data.status', null);

        expect($posting->refresh()->status)->toBeNull();
    });

    test('an invalid status is rejected', function () {
        $posting = postingIn(AccountType::Liability, PostingStatus::Cleared);

        $this->patchJson("/api/v1/financial/postings/{$posting->id}", ['status' => 'settled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Choose a valid status.']);

        expect($posting->refresh()->status)->toBe(PostingStatus::Cleared);
    });

    test('a liability posting cannot lose its status', function () {
        $posting = postingIn(AccountType::Liability, PostingStatus::Cleared);

        $this->patchJson("/api/v1/financial/postings/{$posting->id}", ['status' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'A status is required for asset and liability postings.']);

        expect($posting->refresh()->status)->toBe(PostingStatus::Cleared);
    });
});
