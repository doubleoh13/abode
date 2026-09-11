<?php

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/balance-assertions?financial_account_id=1')->assertUnauthorized();
    $this->postJson('/api/v1/financial/balance-assertions', [])->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/balance-assertions', [])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
    });

    test('an assertion is created and listed for its account', function () {
        $this->postJson('/api/v1/financial/balance-assertions', [
            'financial_account_id' => $this->checking->id,
            'financial_commodity_id' => $this->usd->id,
            'asserted_at' => '2026-01-31',
            'balance' => '1523.47',
            'memo' => 'January statement',
        ])
            ->assertCreated()
            ->assertJsonPath('data.asserted_at', '2026-01-31')
            ->assertJsonPath('data.balance', '1523.47')
            ->assertJsonPath('data.commodity.code', 'USD');

        $other = Account::factory()->ofType(AccountType::Asset)->create();
        BalanceAssertion::factory()->forAccount($other)->ofCommodity($this->usd)->create();

        $this->getJson("/api/v1/financial/balance-assertions?financial_account_id={$this->checking->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.memo', 'January statement');
    });

    test('a holding assertion can reconcile this account\'s postings through its date, and nothing else', function () {
        $groceries = Account::factory()->ofType(AccountType::Expense)->create();
        $savings = Account::factory()->ofType(AccountType::Asset)->create();
        $legs = [];

        foreach ([['2026-01-10', '100', PostingStatus::Cleared], ['2026-01-31', '-40', PostingStatus::Pending], ['2026-02-02', '-10', PostingStatus::Cleared]] as [$date, $amount, $status]) {
            $transaction = Transaction::factory()->on($date)->create();
            Posting::factory()->forTransaction($transaction, 1)->inAccount($groceries)->ofCommodity($this->usd)->create(['amount' => bcmul($amount, '-1', 2), 'status' => null]);
            $legs[$date] = Posting::factory()->forTransaction($transaction, 0)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => $amount, 'status' => $status]);
        }

        $transfer = Transaction::factory()->on('2026-01-15')->create();
        $savingsLeg = Posting::factory()->forTransaction($transfer, 1)->inAccount($savings)->ofCommodity($this->usd)->create(['amount' => '25', 'status' => PostingStatus::Cleared]);
        $legs['transfer'] = Posting::factory()->forTransaction($transfer, 0)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => '-25', 'status' => PostingStatus::Cleared]);

        $this->postJson('/api/v1/financial/balance-assertions', [
            'financial_account_id' => $this->checking->id,
            'financial_commodity_id' => $this->usd->id,
            'asserted_at' => '2026-01-31',
            'balance' => '35',
            'reconcile_postings' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('holds', true)
            ->assertJsonPath('reconciled_postings', 3);

        expect($legs['2026-01-10']->refresh()->status)->toBe(PostingStatus::Reconciled)
            ->and($legs['2026-01-31']->refresh()->status)->toBe(PostingStatus::Reconciled)
            ->and($legs['transfer']->refresh()->status)->toBe(PostingStatus::Reconciled)
            ->and($legs['2026-02-02']->refresh()->status)->toBe(PostingStatus::Cleared)
            ->and($savingsLeg->refresh()->status)->toBe(PostingStatus::Cleared);
    });

    test('a failing assertion is saved but reconciles nothing', function () {
        $transaction = Transaction::factory()->on('2026-01-10')->create();
        $leg = Posting::factory()->forTransaction($transaction, 0)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => '100', 'status' => PostingStatus::Cleared]);

        $this->postJson('/api/v1/financial/balance-assertions', [
            'financial_account_id' => $this->checking->id,
            'financial_commodity_id' => $this->usd->id,
            'asserted_at' => '2026-01-31',
            'balance' => '90',
            'reconcile_postings' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('holds', false)
            ->assertJsonPath('reconciled_postings', 0);

        expect($leg->refresh()->status)->toBe(PostingStatus::Cleared)
            ->and(BalanceAssertion::query()->count())->toBe(1);
    });

    test('a duplicate reconciliation point is rejected', function () {
        BalanceAssertion::factory()->forAccount($this->checking)->ofCommodity($this->usd)
            ->create(['asserted_at' => '2026-01-31']);

        $this->postJson('/api/v1/financial/balance-assertions', [
            'financial_account_id' => $this->checking->id,
            'financial_commodity_id' => $this->usd->id,
            'asserted_at' => '2026-01-31',
            'balance' => '10',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'asserted_at' => 'An assertion already exists for this account, commodity, and date.',
            ]);
    });

    test('an invalid balance is rejected', function () {
        $this->postJson('/api/v1/financial/balance-assertions', [
            'financial_account_id' => $this->checking->id,
            'financial_commodity_id' => $this->usd->id,
            'asserted_at' => '2026-01-31',
            'balance' => 'not-a-number',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['balance' => 'Enter a valid balance.']);
    });

    test('an assertion can be deleted', function () {
        $assertion = BalanceAssertion::factory()->forAccount($this->checking)->ofCommodity($this->usd)->create();

        $this->deleteJson("/api/v1/financial/balance-assertions/{$assertion->id}")->assertNoContent();

        expect(BalanceAssertion::query()->find($assertion->id))->toBeNull();
    });
});
