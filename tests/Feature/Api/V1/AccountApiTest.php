<?php

use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Institution;

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

    test('the index derives full paths for the whole tree', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'dining-out']);

        $this->getJson('/api/v1/financial/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.path', 'expenses:food:dining-out')
            ->assertJsonPath('data.1.path', 'expenses:food');
    });

    test('an account can be created at an institution', function () {
        $institution = Institution::factory()->create();

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'asset',
            'financial_institution_id' => $institution->id,
            'name' => 'checking',
            'opened_at' => '2020-01-15',
        ])->assertCreated()
            ->assertJsonPath('data.path', 'assets:checking')
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
            ->assertJsonValidationErrors('name');
    });

    test('sibling names must be unique', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->postJson('/api/v1/financial/accounts', [
            'account_type' => 'expense',
            'parent_id' => $food->id,
            'name' => 'groceries',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
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
            ->assertJsonValidationErrors('closed_at');
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

    test('deleting an account with children conflicts', function () {
        $food = Account::factory()->create(['name' => 'food']);
        Account::factory()->childOf($food)->create(['name' => 'groceries']);

        $this->deleteJson("/api/v1/financial/accounts/{$food->id}")->assertConflict();
    });

    test('a leaf account can be deleted', function () {
        $account = Account::factory()->create();

        $this->deleteJson("/api/v1/financial/accounts/{$account->id}")->assertNoContent();

        $this->assertModelMissing($account);
    });
});
