<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Institution;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/accounts')->assertUnauthorized();
});

test('a user without view-finances is forbidden', function () {
    actingWithPermissions();

    $this->getJson('/api/v1/financial/accounts')->assertForbidden();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/accounts', [
        'account_type' => 'expense',
        'name' => 'food',
    ])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
    });

    test('balances sum the account posting amounts per commodity', function () {
        $checking = Account::factory()->ofType(AccountType::Asset)->create();
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);
        $transaction = Transaction::factory()->on('2026-01-05')->create();
        Posting::factory()->forTransaction($transaction, 0)->inAccount($checking)->ofCommodity($usd)->create(['amount' => '250.75']);
        Posting::factory()->forTransaction($transaction, 1)->inAccount($checking)->ofCommodity($usd)->create(['amount' => '-100.25']);
        Posting::factory()->forTransaction($transaction, 2)->inAccount($checking)->ofCommodity($fbtc)->create(['amount' => '0.5']);

        $this->getJson("/api/v1/financial/accounts/{$checking->id}/balances")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.financial_commodity_id', $usd->id)
            ->assertJsonPath('data.0.balance', '150.5')
            ->assertJsonPath('data.1.financial_commodity_id', $fbtc->id)
            ->assertJsonPath('data.1.balance', '0.5');

        $later = Transaction::factory()->on('2026-02-10')->create();
        Posting::factory()->forTransaction($later, 0)->inAccount($checking)->ofCommodity($usd)->create(['amount' => '49.5']);

        $this->getJson("/api/v1/financial/accounts/{$checking->id}/balances?as_of=2026-01-05")
            ->assertOk()
            ->assertJsonPath('data.0.balance', '150.5');
    });

    test('balances roll up the subtree per account with include_descendants', function () {
        $savings = Account::factory()->ofType(AccountType::Asset)->create();
        $emergency = Account::factory()->childOf($savings)->create();
        $vacation = Account::factory()->childOf($savings)->create();
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $transaction = Transaction::factory()->on('2026-01-05')->create();
        Posting::factory()->forTransaction($transaction, 0)->inAccount($emergency)->ofCommodity($usd)->create(['amount' => '500']);
        Posting::factory()->forTransaction($transaction, 1)->inAccount($vacation)->ofCommodity($usd)->create(['amount' => '200']);

        $this->getJson("/api/v1/financial/accounts/{$savings->id}/balances")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/financial/accounts/{$savings->id}/balances?include_descendants=1")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.financial_account_id', $emergency->id)
            ->assertJsonPath('data.0.balance', '500')
            ->assertJsonPath('data.1.financial_account_id', $vacation->id)
            ->assertJsonPath('data.1.balance', '200');
    });

    test('required account fields use form language', function () {
        $this->postJson('/api/v1/financial/accounts', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.account_type.0', 'Choose an account type.')
            ->assertJsonPath('errors.name.0', 'Enter an account name.');
    });

    test('the index derives full paths and orders by them', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'dining-out']);
        Account::factory()->ofType(AccountType::Asset)->create(['name' => 'Zebra-Savings']);
        Account::factory()->ofType(AccountType::Asset)->create(['name' => 'checking']);

        $this->getJson('/api/v1/financial/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.path', 'Assets:checking')
            ->assertJsonPath('data.1.path', 'Assets:Zebra-Savings')
            ->assertJsonPath('data.2.path', 'Expenses:food')
            ->assertJsonPath('data.3.path', 'Expenses:food:dining-out');
    });

    test('the index counts each account\'s unmatched bank transactions', function () {
        $checking = Account::factory()->ofType(AccountType::Asset)->create();
        $savings = Account::factory()->ofType(AccountType::Asset)->create();
        $posting = Posting::factory()->inAccount($checking)->create();
        BankTransaction::factory()->inAccount($checking)->count(2)->create();
        BankTransaction::factory()->linkedTo($posting)->create();

        $response = $this->getJson('/api/v1/financial/accounts')->assertOk();

        $counts = collect($response->json('data'))->pluck('unmatched_bank_transactions_count', 'id');

        expect($counts[$checking->id])->toBe(2)
            ->and($counts[$savings->id])->toBe(0);
    });

    test('a SimpleFIN id round trips and must be unique', function () {
        $response = $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'asset',
            'name' => 'checking',
            'simplefin_account_id' => 'ACT-1',
        ])->assertCreated()->assertJsonPath('data.simplefin_account_id', 'ACT-1');

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'asset',
            'name' => 'savings',
            'simplefin_account_id' => 'ACT-1',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.simplefin_account_id.0', 'Another account is already mapped to this SimpleFIN account.');

        $this->putJson("/api/v1/financial/accounts/{$response->json('data.id')}", [
            'account_type' => 'asset',
            'name' => 'checking',
            'simplefin_account_id' => 'ACT-1',
        ])->assertOk()->assertJsonPath('data.simplefin_account_id', 'ACT-1');
    });

    test('an account can be created at an institution', function () {
        $institution = Institution::factory()->create();

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'asset',
            'financial_institution_id' => $institution->id,
            'name' => 'checking',
            'opened_at' => '2020-01-15',
        ])->assertCreated()
            ->assertJsonPath('data.path', 'Assets:checking')
            ->assertJsonPath('data.institution.name', $institution->name);
    });

    test('a child must match its parent type', function () {
        $food = Account::factory()->create(['name' => 'food']);

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'asset',
            'parent_id' => $food->id,
            'name' => 'checking',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('account_type');
    });

    test('a colon is not allowed in a name', function () {
        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'expense',
            'name' => 'food:snacks',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Account names cannot contain a colon.');
    });

    test('sibling names must be unique', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'expense',
            'parent_id' => $food->id,
            'name' => 'groceries',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'An account with this name already exists under the selected parent.');
    });

    test('sibling names must stay distinct as URL segments', function () {
        $taxes = Account::factory()->create(['name' => 'Taxes']);
        Account::factory()->childOf($taxes)->create(['name' => 'Allen-County']);

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'expense',
            'parent_id' => $taxes->id,
            'name' => 'Allen County',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Another account under the selected parent shares this name once it becomes an address.');
    });

    test('renaming keeps an account\'s own URL segment available', function () {
        $taxes = Account::factory()->create(['name' => 'Taxes']);
        $county = Account::factory()->childOf($taxes)->create(['name' => 'Allen-County']);

        $this->putJson("/api/v1/financial/accounts/{$county->id}", [
            'account_type' => 'expense',
            'parent_id' => $taxes->id,
            'name' => 'Allen County',
        ])->assertOk()
            ->assertJsonPath('data.slug_path', 'expenses/taxes/allen-county');
    });

    test('the same name is allowed under a different parent', function () {
        $food = Account::factory()->create(['name' => 'food']);
        $travel = Account::factory()->create(['name' => 'travel']);
        Account::factory()->childOf($food)->create(['name' => 'misc']);

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'expense',
            'parent_id' => $travel->id,
            'name' => 'misc',
        ])->assertCreated();
    });

    test('closing before opening is rejected', function () {
        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'asset',
            'name' => 'checking',
            'opened_at' => '2024-06-01',
            'closed_at' => '2024-01-01',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.closed_at.0', 'The closing date must be on or after the opening date.');
    });

    test('an account cannot be reparented under its own descendant', function () {
        $food = Account::factory()->create(['name' => 'food']);
        $groceries = Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->putJson("/api/v1/financial/accounts/{$food->id}", [
            'account_type' => 'expense',
            'parent_id' => $groceries->id,
            'name' => 'food',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    });

    test('the type of an account with children cannot change', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->putJson("/api/v1/financial/accounts/{$food->id}", [
            'account_type' => 'income',
            'name' => 'food',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('account_type');
    });

    test('renaming an account keeps its own name available', function () {
        $food = Account::factory()->create(['name' => 'food']);

        $this->putJson("/api/v1/financial/accounts/{$food->id}", [
            'account_type' => 'expense',
            'name' => 'food',
        ])->assertOk();
    });

    test('allow_postings is normalized to false for leaf accounts', function () {
        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'expense',
            'name' => 'food',
            'allow_postings' => true,
        ])->assertCreated()
            ->assertJsonPath('data.allow_postings', false);

        $food = Account::query()->where('name', 'food')->firstOrFail();

        $this->putJson("/api/v1/financial/accounts/{$food->id}", [
            'account_type' => 'expense',
            'name' => 'food',
            'allow_postings' => true,
        ])->assertOk()
            ->assertJsonPath('data.allow_postings', false);
    });

    test('allow_postings is stored for accounts with children', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->putJson("/api/v1/financial/accounts/{$food->id}", [
            'account_type' => 'expense',
            'name' => 'food',
            'allow_postings' => true,
        ])->assertOk()
            ->assertJsonPath('data.allow_postings', true);
    });

    test('deleting an account with children conflicts', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->deleteJson("/api/v1/financial/accounts/{$food->id}")->assertConflict();
    });

    test('deleting an account with journal postings conflicts', function () {
        $account = Account::factory()->create();
        Posting::factory()->inAccount($account)->create();

        $this->deleteJson("/api/v1/financial/accounts/{$account->id}")->assertConflict();
    });

    test('a leaf account can be deleted', function () {
        $account = Account::factory()->create();

        $this->deleteJson("/api/v1/financial/accounts/{$account->id}")->assertNoContent();

        $this->assertModelMissing($account);
    });
});
