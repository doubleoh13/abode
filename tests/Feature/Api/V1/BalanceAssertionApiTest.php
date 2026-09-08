<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\Commodity;

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
