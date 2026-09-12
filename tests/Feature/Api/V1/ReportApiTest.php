<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;

function balanceSheetPosting(Account $account, Commodity $commodity, string $amount, string $date, ?Lot $lot = null): Posting
{
    $transaction = Transaction::factory()->on($date)->create();
    $factory = Posting::factory()->forTransaction($transaction, 0)->inAccount($account)->ofCommodity($commodity);

    if ($lot !== null) {
        $factory = $factory->withLot($lot);
    }

    return $factory->create(['amount' => $amount]);
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/reports/balance-sheet')->assertUnauthorized();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
    });

    test('an invalid as_of is rejected', function () {
        $this->getJson('/api/v1/financial/reports/balance-sheet?as_of=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['as_of']);
    });

    test('currency holdings are valued at face with signed section totals', function () {
        $creditCard = Account::factory()->ofType(AccountType::Liability)->create();
        balanceSheetPosting($this->checking, $this->usd, '1500', '2026-01-05');
        balanceSheetPosting($this->checking, $this->usd, '-300', '2026-02-01');
        balanceSheetPosting($creditCard, $this->usd, '-250', '2026-02-01');

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.financial_account_id', $this->checking->id)
            ->assertJsonPath('data.rows.0.account_type', 'asset')
            ->assertJsonPath('data.rows.0.quantity', '1200')
            ->assertJsonPath('data.rows.0.market_value', '1200')
            ->assertJsonPath('data.rows.0.cost_basis', '1200')
            ->assertJsonPath('data.rows.1.financial_account_id', $creditCard->id)
            ->assertJsonPath('data.rows.1.market_value', '-250')
            ->assertJsonPath('data.totals.assets', '1200')
            ->assertJsonPath('data.totals.liabilities', '-250')
            ->assertJsonPath('data.totals.net_worth', '950');
    });

    test('as_of excludes later transactions and zeroed holdings drop out', function () {
        balanceSheetPosting($this->checking, $this->usd, '1000', '2026-01-05');
        balanceSheetPosting($this->checking, $this->usd, '-1000', '2026-03-01');

        $this->getJson('/api/v1/financial/reports/balance-sheet?as_of=2026-02-01')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.quantity', '1000')
            ->assertJsonPath('data.as_of', '2026-02-01');

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonCount(0, 'data.rows')
            ->assertJsonPath('data.totals.net_worth', '0');
    });

    test('income, expense, and equity accounts are excluded', function () {
        $groceries = Account::factory()->ofType(AccountType::Expense)->create();
        balanceSheetPosting($this->checking, $this->usd, '-50', '2026-01-05');
        balanceSheetPosting($groceries, $this->usd, '50', '2026-01-05');

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.financial_account_id', $this->checking->id);
    });

    test('accounts opened after the report date are excluded', function () {
        $laterAccount = Account::factory()
            ->ofType(AccountType::Asset)
            ->create(['opened_at' => '2026-06-01']);
        balanceSheetPosting($laterAccount, $this->usd, '100', '2026-01-05');

        $this->getJson('/api/v1/financial/reports/balance-sheet?as_of=2026-03-01')
            ->assertOk()
            ->assertJsonCount(0, 'data.rows');

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows');
    });

    test('traded holdings use the last price on or before the date', function () {
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);
        CommodityPrice::factory()->create(['financial_commodity_id' => $fbtc->id, 'price' => '100', 'priced_at' => '2026-01-10 21:00:00']);
        CommodityPrice::factory()->create(['financial_commodity_id' => $fbtc->id, 'price' => '150', 'priced_at' => '2026-03-10 21:00:00']);
        $lot = Lot::factory()->ofCommodity($fbtc)->create(['cost' => '1000']);
        balanceSheetPosting($this->checking, $fbtc, '10', '2026-01-10', $lot);
        balanceSheetPosting($this->checking, $fbtc, '-4', '2026-02-15', $lot);

        $this->getJson('/api/v1/financial/reports/balance-sheet?as_of=2026-03-01')
            ->assertOk()
            ->assertJsonPath('data.rows.0.quantity', '6')
            ->assertJsonPath('data.rows.0.price', '100')
            ->assertJsonPath('data.rows.0.market_value', '600')
            ->assertJsonPath('data.rows.0.cost_basis', '600')
            ->assertJsonPath('data.totals.assets', '600');

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonPath('data.rows.0.price', '150')
            ->assertJsonPath('data.rows.0.market_value', '900');
    });

    test('lot basis follows the quantity held in each account, sharing the lots endpoint denominator', function () {
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);
        $brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        CommodityPrice::factory()->create(['financial_commodity_id' => $fbtc->id, 'price' => '200', 'priced_at' => '2026-01-10 21:00:00']);
        $lot = Lot::factory()->ofCommodity($fbtc)->create(['cost' => '1000']);
        balanceSheetPosting($this->checking, $fbtc, '10', '2026-01-10', $lot);
        balanceSheetPosting($this->checking, $fbtc, '-4', '2026-02-01', $lot);
        balanceSheetPosting($brokerage, $fbtc, '4', '2026-02-01', $lot);

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.financial_account_id', $this->checking->id)
            ->assertJsonPath('data.rows.0.cost_basis', '428.5714285714285714285714285')
            ->assertJsonPath('data.rows.1.financial_account_id', $brokerage->id)
            ->assertJsonPath('data.rows.1.cost_basis', '285.7142857142857142857142857');
    });

    test('an unpriced traded holding has null value and basis and stays out of totals', function () {
        $token = Commodity::factory()->create();
        balanceSheetPosting($this->checking, $token, '5', '2026-01-05');
        balanceSheetPosting($this->checking, $this->usd, '100', '2026-01-05');

        $this->getJson('/api/v1/financial/reports/balance-sheet')
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.1.financial_commodity_id', $token->id)
            ->assertJsonPath('data.rows.1.market_value', null)
            ->assertJsonPath('data.rows.1.cost_basis', null)
            ->assertJsonPath('data.totals.assets', '100')
            ->assertJsonPath('data.totals.net_worth', '100');
    });
});

describe('income statement', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
    });

    test('a window before its start is rejected', function () {
        $this->getJson('/api/v1/financial/reports/income-statement?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    });

    test('activity within the window is reported with income and expenses both positive', function () {
        $salary = Account::factory()->ofType(AccountType::Income)->create();
        $utilities = Account::factory()->ofType(AccountType::Expense)->create();
        $electricity = Account::factory()->childOf($utilities)->create();
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);

        balanceSheetPosting($salary, $this->usd, '-5000', '2026-01-15');
        balanceSheetPosting($electricity, $this->usd, '120', '2026-01-20');
        balanceSheetPosting($electricity, $this->usd, '-20', '2026-02-02');
        balanceSheetPosting($salary, $fbtc, '-0.5', '2026-02-10');
        balanceSheetPosting($utilities, $this->usd, '999', '2025-12-31');
        balanceSheetPosting($this->checking, $this->usd, '5000', '2026-01-15');

        $this->getJson('/api/v1/financial/reports/income-statement?from=2026-01-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-01-01')
            ->assertJsonPath('data.to', '2026-03-31')
            ->assertJsonCount(3, 'data.rows')
            ->assertJsonPath('data.rows.0.financial_account_id', $salary->id)
            ->assertJsonPath('data.rows.0.account_type', 'income')
            ->assertJsonPath('data.rows.0.amount', '5000')
            ->assertJsonPath('data.rows.1.financial_commodity_id', $fbtc->id)
            ->assertJsonPath('data.rows.1.amount', '0.5')
            ->assertJsonPath('data.rows.2.financial_account_id', $electricity->id)
            ->assertJsonPath('data.rows.2.amount', '100')
            ->assertJsonPath('data.totals.income', '5000')
            ->assertJsonPath('data.totals.expenses', '100')
            ->assertJsonPath('data.totals.net', '4900');
    });
});
