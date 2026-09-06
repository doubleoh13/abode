<?php

use App\Enums\Financial\AccountType;
use App\Models\Financial\Account;
use Illuminate\Database\QueryException;

test('path derives from ancestry with the type prefix', function () {
    $food = Account::factory()->create(['name' => 'food']);
    $diningOut = Account::factory()->childOf($food)->create(['name' => 'dining-out']);

    expect($food->path)->toBe('expenses:food')
        ->and($diningOut->path)->toBe('expenses:food:dining-out');
});

test('path prefixes follow ledger naming', function (AccountType $accountType, string $expectedPath) {
    $account = Account::factory()->ofType($accountType)->create(['name' => 'root']);

    expect($account->path)->toBe($expectedPath);
})->with([
    'asset' => [AccountType::Asset, 'assets:root'],
    'liability' => [AccountType::Liability, 'liabilities:root'],
    'income' => [AccountType::Income, 'income:root'],
    'expense' => [AccountType::Expense, 'expenses:root'],
    'equity' => [AccountType::Equity, 'equity:root'],
]);

test('children resolve through the self-referential relationship', function () {
    $food = Account::factory()->create(['name' => 'food']);
    Account::factory()->childOf($food)->create(['name' => 'groceries']);
    Account::factory()->childOf($food)->create(['name' => 'dining-out']);

    expect($food->children()->pluck('name')->sort()->values()->all())
        ->toBe(['dining-out', 'groceries']);
});

test('deleting an account with children is restricted', function () {
    $food = Account::factory()->create(['name' => 'food']);
    Account::factory()->childOf($food)->create(['name' => 'groceries']);

    expect(fn () => $food->delete())->toThrow(QueryException::class);
});
