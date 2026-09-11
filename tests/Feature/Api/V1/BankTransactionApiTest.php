<?php

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/bank-transactions')->assertUnauthorized();
});

test('a viewer cannot match or ignore', function () {
    actingWithPermissions(Permission::ViewFinances);
    $row = BankTransaction::factory()->create();

    $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", [])->assertForbidden();
    $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/ignore")->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
        config()->set('financial.simplefin.match_window_days', 5);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
        $this->groceries = Account::factory()->ofType(AccountType::Expense)->create();
    });

    function checkingLeg(string $date, string $amount, PostingStatus $status = PostingStatus::Pending): Posting
    {
        $transaction = Transaction::factory()->on($date)->create(['memo' => "Leg {$amount}"]);
        Posting::factory()->forTransaction($transaction, 1)->inAccount(test()->groceries)->ofCommodity(test()->usd)->create(['amount' => bcmul($amount, '-1', 25)]);

        return Posting::factory()->forTransaction($transaction, 0)->inAccount(test()->checking)->ofCommodity(test()->usd)->create(['amount' => $amount, 'status' => $status]);
    }

    test('the inbox lists unresolved rows newest first with candidates by account, amount, and window', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-09', 'amount' => '-42.10', 'description' => 'KROGER']);
        $older = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-01', 'amount' => '-5']);
        BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-10', 'ignored_at' => now()]);
        $close = checkingLeg('2026-09-08', '-42.10');
        $far = checkingLeg('2026-09-20', '-42.10');
        $otherAmount = checkingLeg('2026-09-09', '-42.11');
        $taken = checkingLeg('2026-09-09', '-42.10');
        BankTransaction::factory()->linkedTo($taken)->create();

        $this->getJson('/api/v1/financial/bank-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.amount', '-42.1')
            ->assertJsonPath('data.0.description', 'KROGER')
            ->assertJsonPath('data.0.account.id', $this->checking->id)
            ->assertJsonCount(1, 'data.0.candidates')
            ->assertJsonPath('data.0.candidates.0.id', $close->id)
            ->assertJsonPath('data.0.candidates.0.transaction.memo', 'Leg -42.10')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonCount(0, 'data.1.candidates');

        expect([$far->id, $otherAmount->id, $taken->id])->each->not->toBe($close->id);

        $this->getJson('/api/v1/financial/bank-transactions?state=ignored')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.candidates');
    });

    test('matching links the posting and clears it when the bank says posted', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10', 'pending' => false]);
        $posting = checkingLeg('2026-09-08', '-42.10');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $posting->id])
            ->assertOk()
            ->assertJsonPath('data.financial_posting_id', $posting->id);

        expect($posting->fresh()->status)->toBe(PostingStatus::Cleared)
            ->and($row->fresh()->financial_posting_id)->toBe($posting->id);

        $this->getJson('/api/v1/financial/bank-transactions')->assertOk()->assertJsonCount(0, 'data');
    });

    test('matching a pending bank row leaves the posting status alone', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10', 'pending' => true]);
        $posting = checkingLeg('2026-09-08', '-42.10');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $posting->id])->assertOk();

        expect($posting->fresh()->status)->toBe(PostingStatus::Pending);
    });

    test('a match must be on the same account, for the same amount, and unclaimed', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10']);
        $wrongAmount = checkingLeg('2026-09-08', '-42.11');
        $taken = checkingLeg('2026-09-08', '-42.10');
        BankTransaction::factory()->linkedTo($taken)->create();
        $otherAccountPosting = $wrongAmount->transaction->postings()->where('financial_account_id', $this->groceries->id)->firstOrFail();

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $wrongAmount->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.financial_posting_id.0', 'The posting amount does not equal the bank transaction amount.');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $taken->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.financial_posting_id.0', 'That posting already settles another bank transaction.');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $otherAccountPosting->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.financial_posting_id.0', 'The posting is on a different account than the bank transaction.');
    });

    test('ignoring removes a row from the inbox and unignoring returns it', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create();

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/ignore")->assertOk();
        expect($row->fresh()->ignored_at)->not->toBeNull();
        $this->getJson('/api/v1/financial/bank-transactions')->assertOk()->assertJsonCount(0, 'data');

        $this->deleteJson("/api/v1/financial/bank-transactions/{$row->id}/ignore")->assertOk();
        expect($row->fresh()->ignored_at)->toBeNull();
        $this->getJson('/api/v1/financial/bank-transactions')->assertOk()->assertJsonCount(1, 'data');
    });

    test('deleting a matched transaction returns its bank row to the inbox', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10']);
        $posting = checkingLeg('2026-09-08', '-42.10');
        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $posting->id])->assertOk();

        $this->deleteJson("/api/v1/financial/transactions/{$posting->financial_transaction_id}")->assertNoContent();

        expect($row->fresh()->financial_posting_id)->toBeNull();
        $this->getJson('/api/v1/financial/bank-transactions')->assertOk()->assertJsonCount(1, 'data');
    });
});
