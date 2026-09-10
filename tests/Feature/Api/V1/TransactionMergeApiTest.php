<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use App\Models\Note;

function mergeLeg(Account $account, Commodity $commodity, int|string $amount, array $overrides = []): array
{
    return [
        'status' => in_array($account->account_type, [AccountType::Asset, AccountType::Liability], true) ? 'cleared' : null,
        'financial_account_id' => $account->id,
        'financial_commodity_id' => $commodity->id,
        'amount' => (string) $amount,
        ...$overrides,
    ];
}

test('a viewer cannot merge', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/transactions/merge', [])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
        $this->groceries = Account::factory()->ofType(AccountType::Expense)->create();
        $this->uncategorized = Account::factory()->ofType(AccountType::Expense)->create();
    });

    function twoLeggedTransaction(string $date, Account $from, Account $to, string $amount, array $attributes = []): Transaction
    {
        $transaction = Transaction::factory()->on($date)->create($attributes);
        Posting::factory()->forTransaction($transaction, 0)->inAccount($from)->ofCommodity(test()->usd)->create(['amount' => '-'.$amount]);
        Posting::factory()->forTransaction($transaction, 1)->inAccount($to)->ofCommodity(test()->usd)->create(['amount' => $amount]);

        return $transaction;
    }

    test('the ids are validated', function () {
        $only = twoLeggedTransaction('2026-08-01', $this->checking, $this->groceries, '10');

        $this->postJson('/api/v1/financial/transactions/merge', [
            'date' => '2026-08-01',
            'financial_transaction_ids' => [$only->id, $only->id],
            'postings' => [
                mergeLeg($this->checking, $this->usd, -10),
                mergeLeg($this->groceries, $this->usd, 10),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['financial_transaction_ids.0' => 'Choose two different transactions.']);

        $this->postJson('/api/v1/financial/transactions/merge', ['financial_transaction_ids' => [$only->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.financial_transaction_ids.0', 'Choose exactly two transactions to merge.');
    });

    test('two transactions collapse into one carrying notes, attachments, and the schedule link', function () {
        $schedule = RecurringTransaction::factory()->create();
        $manual = twoLeggedTransaction('2026-08-01', $this->checking, $this->groceries, '42.10', ['memo' => 'Kroger', 'financial_recurring_transaction_id' => $schedule->id]);
        $imported = twoLeggedTransaction('2026-08-03', $this->checking, $this->uncategorized, '42.10', ['memo' => 'KROGER #123']);
        Note::factory()->create(['noteable_type' => 'financial.transaction', 'noteable_id' => $manual->id]);
        Attachment::factory()->create(['attachable_type' => 'financial.transaction', 'attachable_id' => $imported->id]);

        $response = $this->postJson('/api/v1/financial/transactions/merge', [
            'date' => '2026-08-03',
            'memo' => 'Kroger',
            'financial_transaction_ids' => [$manual->id, $imported->id],
            'postings' => [
                mergeLeg($this->checking, $this->usd, '-42.10'),
                mergeLeg($this->groceries, $this->usd, '42.10'),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-08-03')
            ->assertJsonPath('data.memo', 'Kroger')
            ->assertJsonPath('data.financial_recurring_transaction_id', $schedule->id)
            ->assertJsonPath('data.postings.1.account.id', $this->groceries->id);

        $merged = Transaction::query()->findOrFail($response->json('data.id'));

        expect(Transaction::query()->count())->toBe(1)
            ->and($merged->notes()->count())->toBe(1)
            ->and($merged->attachments()->count())->toBe(1);
    });

    test('a merge may re-reference a lot opened by an absorbed transaction', function () {
        $brokerage = Account::factory()->ofType(AccountType::Asset)->create();
        $fbtc = Commodity::factory()->create(['display_precision' => 8]);
        $lot = Lot::factory()->ofCommodity($fbtc)->create(['cost' => '1000', 'acquired_at' => '2026-08-01']);
        $buy = Transaction::factory()->on('2026-08-01')->create();
        Posting::factory()->forTransaction($buy, 0)->inAccount($brokerage)->withLot($lot)->create(['amount' => '2']);
        Posting::factory()->forTransaction($buy, 1)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => '-1000']);
        $duplicate = twoLeggedTransaction('2026-08-01', $this->checking, $this->uncategorized, '1000');

        $response = $this->postJson('/api/v1/financial/transactions/merge', [
            'date' => '2026-08-01',
            'financial_transaction_ids' => [$buy->id, $duplicate->id],
            'postings' => [
                mergeLeg($brokerage, $fbtc, '2', ['financial_lot_id' => $lot->id]),
                mergeLeg($this->checking, $this->usd, '-1000'),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.postings.0.financial_lot_id', $lot->id);

        expect(Transaction::query()->count())->toBe(1)
            ->and(Lot::query()->count())->toBe(1)
            ->and((string) $lot->fresh()->acquiredQuantity())->toBe('2.0000000000000000000000000');
    });

    test('an unbalanced merge is rejected and leaves both originals', function () {
        $first = twoLeggedTransaction('2026-08-01', $this->checking, $this->groceries, '10');
        $second = twoLeggedTransaction('2026-08-01', $this->checking, $this->uncategorized, '10');

        $this->postJson('/api/v1/financial/transactions/merge', [
            'date' => '2026-08-01',
            'financial_transaction_ids' => [$first->id, $second->id],
            'postings' => [
                mergeLeg($this->checking, $this->usd, -10),
                mergeLeg($this->groceries, $this->usd, 10),
                mergeLeg($this->uncategorized, $this->usd, 10),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.postings.0', 'Postings must balance at cost.');

        expect(Transaction::query()->count())->toBe(2);
    });
});
