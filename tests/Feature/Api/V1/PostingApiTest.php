<?php

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Enums\Permission;
use App\Models\Financial\Account;
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

    test('the register requires an account', function () {
        $this->getJson('/api/v1/financial/postings')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('financial_account_id');
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

        $this->getJson("/api/v1/financial/postings?financial_account_id={$checking->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount', '-20.5')
            ->assertJsonPath('data.0.running_balance', '49.5')
            ->assertJsonPath('data.1.running_balance', '70')
            ->assertJsonPath('data.2.running_balance', '100')
            ->assertJsonPath('data.0.transaction.date', '2026-01-20');
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
