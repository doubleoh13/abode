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
    $this->getJson('/api/v1/financial/bank-transactions?financial_account_id=1')->assertUnauthorized();
});

test('the account is required', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->getJson('/api/v1/financial/bank-transactions')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('financial_account_id');
});

test('a viewer cannot match or reject', function () {
    actingWithPermissions(Permission::ViewFinances);
    $row = BankTransaction::factory()->create();

    $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => 1])->assertForbidden();
    $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/reject", ['financial_posting_id' => 1])->assertForbidden();
    $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/unmatch")->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
        config()->set('financial.simplefin.match_window_days', 3);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
        $this->groceries = Account::factory()->ofType(AccountType::Expense)->create();
    });

    function checkingLeg(string $date, string $amount, PostingStatus $status = PostingStatus::Pending): Posting
    {
        $transaction = Transaction::factory()->on($date)->create();
        Posting::factory()->forTransaction($transaction, 1)->inAccount(test()->groceries)->ofCommodity(test()->usd)->create(['amount' => bcmul($amount, '-1', 25)]);

        return Posting::factory()->forTransaction($transaction, 0)->inAccount(test()->checking)->ofCommodity(test()->usd)->create(['amount' => $amount, 'status' => $status]);
    }

    test('unmatched rows are listed newest first with plain matches proposed', function () {
        $proposed = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-09', 'amount' => '-42.10', 'description' => 'KROGER']);
        $farOff = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-01', 'amount' => '-5', 'pending' => true]);
        BankTransaction::factory()->linkedTo(checkingLeg('2026-09-05', '-9.99'))->create();
        BankTransaction::factory()->create(['posted_on' => '2026-09-10']);
        $match = checkingLeg('2026-09-07', '-42.10');
        checkingLeg('2026-09-20', '-42.10');
        checkingLeg('2026-09-09', '-42.11');
        checkingLeg('2026-09-05', '-5');

        $this->getJson("/api/v1/financial/bank-transactions?financial_account_id={$this->checking->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $proposed->id)
            ->assertJsonPath('data.0.amount', '-42.1')
            ->assertJsonPath('data.0.description', 'KROGER')
            ->assertJsonPath('data.0.candidate_posting_id', $match->id)
            ->assertJsonPath('data.0.rejected_posting_ids', [])
            ->assertJsonPath('data.1.id', $farOff->id)
            ->assertJsonPath('data.1.pending', true)
            ->assertJsonPath('data.1.candidate_posting_id', null);
    });

    test('an ambiguous pairing, a linked posting, or a rejected posting is never proposed', function () {
        $twin = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-09', 'amount' => '-20']);
        BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-10', 'amount' => '-20']);
        checkingLeg('2026-09-09', '-20');

        $taken = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-01', 'amount' => '-7']);
        BankTransaction::factory()->linkedTo(checkingLeg('2026-09-01', '-7'))->create();

        $declined = checkingLeg('2026-08-20', '-3');
        $rejected = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-08-20', 'amount' => '-3', 'rejected_posting_ids' => [$declined->id]]);

        $response = $this->getJson("/api/v1/financial/bank-transactions?financial_account_id={$this->checking->id}")->assertOk()->assertJsonCount(4, 'data');

        expect(collect($response->json('data'))->pluck('candidate_posting_id', 'id')->all())
            ->toBe([$twin->id + 1 => null, $twin->id => null, $taken->id => null, $rejected->id => null]);
    });

    test('approving links the posting and clears it when the bank says posted', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10', 'pending' => false]);
        $posting = checkingLeg('2026-09-08', '-42.10');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $posting->id])
            ->assertOk()
            ->assertJsonPath('data.financial_posting_id', $posting->id);

        expect($posting->refresh()->status)->toBe(PostingStatus::Cleared)
            ->and($posting->bankTransaction->id)->toBe($row->id);

        $this->getJson("/api/v1/financial/bank-transactions?financial_account_id={$this->checking->id}")->assertOk()->assertJsonCount(0, 'data');
    });

    test('matching a pending bank row leaves the posting status alone', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10', 'pending' => true]);
        $posting = checkingLeg('2026-09-08', '-42.10');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $posting->id])->assertOk();

        expect($posting->refresh()->status)->toBe(PostingStatus::Pending);
    });

    test('matching rejects a posting from another account or one already settled', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['amount' => '-42.10']);
        $elsewhere = Posting::factory()->inAccount($this->groceries)->ofCommodity($this->usd)->create();
        $settled = checkingLeg('2026-09-08', '-42.10');
        BankTransaction::factory()->linkedTo($settled)->create();

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $elsewhere->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.financial_posting_id.0', 'The posting must be in the same account as the bank transaction.');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $settled->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.financial_posting_id.0', 'That posting already settles another bank transaction.');
    });

    test('an already matched row cannot be matched or rejected again', function () {
        $posting = checkingLeg('2026-09-08', '-42.10');
        $row = BankTransaction::factory()->linkedTo($posting)->create();
        $other = checkingLeg('2026-09-09', '-42.10');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/match", ['financial_posting_id' => $other->id])
            ->assertConflict()
            ->assertJsonPath('message', 'This bank transaction is already matched.');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/reject", ['financial_posting_id' => $other->id])->assertConflict();
    });

    test('a new transaction can settle a bank row through its posting', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-04', 'amount' => '150', 'pending' => false]);

        $response = $this->postJson('/api/v1/financial/transactions', [
            'date' => '2026-09-04',
            'postings' => [
                ['financial_account_id' => $this->checking->id, 'financial_commodity_id' => $this->usd->id, 'amount' => '150', 'status' => 'cleared', 'financial_bank_transaction_id' => $row->id],
                ['financial_account_id' => $this->groceries->id, 'financial_commodity_id' => $this->usd->id, 'amount' => '-150'],
            ],
        ])->assertCreated();

        $postingId = $response->json('data.postings.0.id');

        expect($row->refresh()->financial_posting_id)->toBe($postingId)
            ->and($response->json('data.postings.0.bank_transaction.id'))->toBe($row->id);

        $this->getJson("/api/v1/financial/bank-transactions?financial_account_id={$this->checking->id}")->assertOk()->assertJsonCount(0, 'data');
    });

    test('a posting cannot settle a bank row from another account or one already matched', function () {
        $elsewhere = BankTransaction::factory()->create(['amount' => '150']);
        $settled = BankTransaction::factory()->linkedTo(checkingLeg('2026-09-01', '150'))->create();

        $payload = fn (int $bankTransactionId): array => [
            'date' => '2026-09-04',
            'postings' => [
                ['financial_account_id' => $this->checking->id, 'financial_commodity_id' => $this->usd->id, 'amount' => '150', 'status' => 'cleared', 'financial_bank_transaction_id' => $bankTransactionId],
                ['financial_account_id' => $this->groceries->id, 'financial_commodity_id' => $this->usd->id, 'amount' => '-150'],
            ],
        ];

        $this->postJson('/api/v1/financial/transactions', $payload($elsewhere->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['postings.0.financial_bank_transaction_id' => 'The bank transaction belongs to a different account.']);

        $this->postJson('/api/v1/financial/transactions', $payload($settled->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['postings.0.financial_bank_transaction_id' => 'That bank transaction is already matched.']);
    });

    test('unmatching frees the row and declines the posting it settled', function () {
        $posting = checkingLeg('2026-09-08', '-42.10', PostingStatus::Cleared);
        $row = BankTransaction::factory()->linkedTo($posting)->create(['posted_on' => '2026-09-09']);

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/unmatch")
            ->assertOk()
            ->assertJsonPath('data.financial_posting_id', null)
            ->assertJsonPath('data.rejected_posting_ids', [$posting->id]);

        expect($posting->refresh()->status)->toBe(PostingStatus::Cleared);

        $this->getJson("/api/v1/financial/bank-transactions?financial_account_id={$this->checking->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.candidate_posting_id', null);

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/unmatch")
            ->assertConflict()
            ->assertJsonPath('message', 'This bank transaction is not matched.');
    });

    test('rejecting a proposal returns the row to the unmatched list without that candidate', function () {
        $row = BankTransaction::factory()->inAccount($this->checking)->create(['posted_on' => '2026-09-09', 'amount' => '-42.10']);
        $posting = checkingLeg('2026-09-08', '-42.10');

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/reject", ['financial_posting_id' => $posting->id])
            ->assertOk()
            ->assertJsonPath('data.rejected_posting_ids', [$posting->id])
            ->assertJsonPath('data.financial_posting_id', null);

        $this->postJson("/api/v1/financial/bank-transactions/{$row->id}/reject", ['financial_posting_id' => $posting->id])
            ->assertOk()
            ->assertJsonPath('data.rejected_posting_ids', [$posting->id]);

        $this->getJson("/api/v1/financial/bank-transactions?financial_account_id={$this->checking->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.candidate_posting_id', null);
    });
});
