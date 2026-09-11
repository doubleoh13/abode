<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

function journalIssuePosting(Transaction $transaction, int $position, Account $account, Commodity $commodity, int $amount, ?Lot $lot = null): Posting
{
    $factory = Posting::factory()->forTransaction($transaction, $position)->inAccount($account)->ofCommodity($commodity);

    if ($lot !== null) {
        $factory = $factory->withLot($lot);
    }

    return $factory->create(['amount' => $amount]);
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/journal-issues')->assertUnauthorized();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
        $this->gains = Account::factory()->ofType(AccountType::Income)->create();
        $this->fbtc = Commodity::factory()->create(['display_precision' => 8]);
    });

    test('a clean journal reports no issues', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create(['cost' => 100]);
        $buy = Transaction::factory()->on('2026-01-05')->create();
        journalIssuePosting($buy, 0, $this->brokerage, $this->fbtc, 10, $lot);
        journalIssuePosting($buy, 1, $this->checking, $this->usd, -100);

        $this->getJson('/api/v1/financial/journal-issues')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('the first posting driving a lot negative is flagged', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create(['cost' => 100]);
        $buy = Transaction::factory()->on('2026-01-05')->create();
        journalIssuePosting($buy, 0, $this->brokerage, $this->fbtc, 100, $lot);
        journalIssuePosting($buy, 1, $this->checking, $this->usd, -100);

        $firstSale = Transaction::factory()->on('2026-02-01')->create();
        journalIssuePosting($firstSale, 0, $this->brokerage, $this->fbtc, -80, $lot);
        journalIssuePosting($firstSale, 1, $this->checking, $this->usd, 80);

        $secondSale = Transaction::factory()->on('2026-03-01')->create();
        $offender = journalIssuePosting($secondSale, 0, $this->brokerage, $this->fbtc, -80, $lot);
        journalIssuePosting($secondSale, 1, $this->checking, $this->usd, 80);

        $response = $this->getJson('/api/v1/financial/journal-issues')->assertOk();

        $negativeLotIssues = collect($response->json('data'))->where('type', 'negative_lot')->values();

        expect($negativeLotIssues)->toHaveCount(1)
            ->and($negativeLotIssues[0]['financial_lot_id'])->toBe($lot->id)
            ->and($negativeLotIssues[0]['financial_transaction_id'])->toBe($secondSale->id)
            ->and($negativeLotIssues[0]['financial_posting_id'])->toBe($offender->id);
    });

    test('a balance assertion is checked end-of-day inclusive', function () {
        $deposit = Transaction::factory()->on('2026-01-31')->create();
        journalIssuePosting($deposit, 0, $this->checking, $this->usd, 100);
        journalIssuePosting($deposit, 1, $this->gains, $this->usd, -100);

        BalanceAssertion::factory()
            ->forAccount($this->checking)->ofCommodity($this->usd)
            ->create(['asserted_at' => '2026-01-31', 'balance' => 100]);

        $this->getJson('/api/v1/financial/journal-issues')->assertOk()->assertJsonCount(0, 'data');

        $backdated = Transaction::factory()->on('2026-01-15')->create();
        journalIssuePosting($backdated, 0, $this->checking, $this->usd, 25);
        journalIssuePosting($backdated, 1, $this->gains, $this->usd, -25);

        $response = $this->getJson('/api/v1/financial/journal-issues')->assertOk();
        $failed = collect($response->json('data'))->where('type', 'failed_assertion')->values();

        expect($failed)->toHaveCount(1)
            ->and($failed[0]['expected'])->toBe('100')
            ->and($failed[0]['actual'])->toBe('125')
            ->and($failed[0]['financial_account_id'])->toBe($this->checking->id);
    });

    test('a parent account assertion is checked against its subtree', function () {
        $savings = Account::factory()->ofType(AccountType::Asset)->create();
        $emergency = Account::factory()->childOf($savings)->create();
        $deposit = Transaction::factory()->on('2026-01-31')->create();
        journalIssuePosting($deposit, 0, $emergency, $this->usd, 100);
        journalIssuePosting($deposit, 1, $this->gains, $this->usd, -100);

        BalanceAssertion::factory()
            ->forAccount($savings)->ofCommodity($this->usd)
            ->create(['asserted_at' => '2026-01-31', 'balance' => 100]);

        $this->getJson('/api/v1/financial/journal-issues')->assertOk()->assertJsonCount(0, 'data');

        $backdated = Transaction::factory()->on('2026-01-15')->create();
        journalIssuePosting($backdated, 0, $emergency, $this->usd, 25);
        journalIssuePosting($backdated, 1, $this->gains, $this->usd, -25);

        $response = $this->getJson('/api/v1/financial/journal-issues')->assertOk();
        $failed = collect($response->json('data'))->where('type', 'failed_assertion')->values();

        expect($failed)->toHaveCount(1)
            ->and($failed[0]['actual'])->toBe('125')
            ->and($failed[0]['financial_account_id'])->toBe($savings->id);
    });

    test('a lot edit retroactively unbalances dependent transactions', function () {
        $lot = Lot::factory()->ofCommodity($this->fbtc)->create(['cost' => 100]);
        $buy = Transaction::factory()->on('2026-01-05')->create();
        journalIssuePosting($buy, 0, $this->brokerage, $this->fbtc, 10, $lot);
        journalIssuePosting($buy, 1, $this->checking, $this->usd, -100);

        $sale = Transaction::factory()->on('2026-02-01')->create();
        journalIssuePosting($sale, 0, $this->brokerage, $this->fbtc, -5, $lot);
        journalIssuePosting($sale, 1, $this->checking, $this->usd, 60);
        journalIssuePosting($sale, 2, $this->gains, $this->usd, -10);

        $this->getJson('/api/v1/financial/journal-issues')->assertOk()->assertJsonCount(0, 'data');

        $lot->update(['cost' => 120]);

        $response = $this->getJson('/api/v1/financial/journal-issues')->assertOk();

        $unbalanced = collect($response->json('data'))->where('type', 'unbalanced_transaction')->keyBy('financial_transaction_id');

        expect($unbalanced)->toHaveCount(2)
            ->and($unbalanced[$buy->id]['residual'])->toBe('20')
            ->and($unbalanced[$sale->id]['residual'])->toBe('-10');
    });
});
