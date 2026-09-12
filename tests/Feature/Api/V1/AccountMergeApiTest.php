<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use App\Models\Financial\RecurringPosting;
use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use App\Models\Note;

test('a viewer cannot merge accounts', function () {
    actingWithPermissions(Permission::ViewFinances);
    $source = Account::factory()->create();
    $target = Account::factory()->create();

    $this->postJson("/api/v1/financial/accounts/{$source->id}/merge", ['target_account_id' => $target->id])
        ->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
    });

    test('everything referencing the source moves to the target and the source is deleted', function () {
        $taxes = Account::factory()->ofType(AccountType::Expense)->create(['name' => 'Taxes']);
        $source = Account::factory()->childOf($taxes)->create(['name' => 'TY2025']);
        $target = Account::factory()->childOf($taxes)->create(['name' => 'All years']);
        $sourceChild = Account::factory()->childOf($source)->create(['name' => 'County']);
        $checking = Account::factory()->ofType(AccountType::Asset)->create();

        $transaction = Transaction::factory()->on('2026-01-05')->create();
        $posting = Posting::factory()->forTransaction($transaction, 0)->inAccount($source)->ofCommodity($this->usd)->create(['amount' => '100']);
        Posting::factory()->forTransaction($transaction, 1)->inAccount($checking)->ofCommodity($this->usd)->create(['amount' => '-100']);
        $bankRow = BankTransaction::factory()->inAccount($source)->create();
        $schedule = RecurringTransaction::factory()->create();
        $template = RecurringPosting::factory()->forSchedule($schedule, 0)->inAccount($source)->ofCommodity($this->usd)->create();
        $note = Note::factory()->for($source, 'noteable')->create();
        BalanceAssertion::factory()->forAccount($source)->ofCommodity($this->usd)->create(['asserted_at' => '2026-01-31', 'balance' => '100']);
        $kept = BalanceAssertion::factory()->forAccount($target)->ofCommodity($this->usd)->create(['asserted_at' => '2026-01-31', 'balance' => '0']);

        $this->postJson("/api/v1/financial/accounts/{$source->id}/merge", ['target_account_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.allow_postings', true);

        expect(Account::query()->find($source->id))->toBeNull()
            ->and($posting->refresh()->financial_account_id)->toBe($target->id)
            ->and($bankRow->refresh()->financial_account_id)->toBe($target->id)
            ->and($template->refresh()->financial_account_id)->toBe($target->id)
            ->and($note->refresh()->noteable_id)->toBe($target->id)
            ->and($sourceChild->refresh()->parent_id)->toBe($target->id)
            ->and(BalanceAssertion::query()->pluck('id')->all())->toBe([$kept->id]);
    });

    test('the SimpleFIN mapping moves when the target has none and conflicts when both are mapped', function () {
        $source = Account::factory()->ofType(AccountType::Asset)->create(['simplefin_account_id' => 'ACT-1', 'simplefin_balance' => '12.5']);
        $target = Account::factory()->ofType(AccountType::Asset)->create();

        $this->postJson("/api/v1/financial/accounts/{$source->id}/merge", ['target_account_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.simplefin_account_id', 'ACT-1')
            ->assertJsonPath('data.simplefin_balance', '12.5');

        $mappedSource = Account::factory()->ofType(AccountType::Asset)->create(['simplefin_account_id' => 'ACT-2']);

        $this->postJson("/api/v1/financial/accounts/{$mappedSource->id}/merge", ['target_account_id' => $target->id])
            ->assertConflict();

        expect(Account::query()->find($mappedSource->id))->not->toBeNull();
    });

    test('the target must be a different account of the same type outside the source subtree', function () {
        $source = Account::factory()->ofType(AccountType::Expense)->create();
        $descendant = Account::factory()->childOf($source)->create();
        $asset = Account::factory()->ofType(AccountType::Asset)->create();

        foreach ([$source, $descendant, $asset] as $target) {
            $this->postJson("/api/v1/financial/accounts/{$source->id}/merge", ['target_account_id' => $target->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('target_account_id');
        }
    });

    test('a child name clash under the target is rejected', function () {
        $source = Account::factory()->ofType(AccountType::Expense)->create();
        $target = Account::factory()->ofType(AccountType::Expense)->create();
        Account::factory()->childOf($source)->create(['name' => 'Allen County']);
        Account::factory()->childOf($target)->create(['name' => 'Allen-County']);

        $this->postJson("/api/v1/financial/accounts/{$source->id}/merge", ['target_account_id' => $target->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target_account_id.0', 'The target already has a child account named Allen County.');
    });
});
