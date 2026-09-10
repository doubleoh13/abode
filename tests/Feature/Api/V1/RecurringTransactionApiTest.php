<?php

use App\Enums\Financial\AccountType;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Payee;
use App\Models\Financial\RecurringPosting;
use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use Carbon\CarbonImmutable;

function scheduleLeg(Account $account, Commodity $commodity, int|string $amount, ?string $status = 'pending'): array
{
    return [
        'status' => in_array($account->account_type, [AccountType::Asset, AccountType::Liability], true) ? $status : null,
        'financial_account_id' => $account->id,
        'financial_commodity_id' => $commodity->id,
        'amount' => (string) $amount,
    ];
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/recurring-transactions')->assertUnauthorized();
});

test('a viewer cannot write', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->postJson('/api/v1/financial/recurring-transactions', [])->assertForbidden();
    $this->postJson('/api/v1/financial/recurring-transactions/preview', [])->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
        CarbonImmutable::setTestNow('2026-09-10');
        config()->set('financial.recurring_lead_days', 14);

        $this->usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $this->checking = Account::factory()->ofType(AccountType::Asset)->create();
        $this->mortgageFund = Account::factory()->ofType(AccountType::Asset)->create();
        $this->income = Account::factory()->ofType(AccountType::Income)->create();
    });

    afterEach(fn () => CarbonImmutable::setTestNow());

    function mortgageTransferPayload(array $overrides = []): array
    {
        return [
            'date' => '2026-09-30',
            'memo' => 'Mortgage payment',
            'frequency' => 'monthly',
            'interval' => 1,
            'postings' => [
                scheduleLeg(test()->checking, test()->usd, '-1712.77'),
                scheduleLeg(test()->mortgageFund, test()->usd, '1712.77'),
            ],
            ...$overrides,
        ];
    }

    test('schedule fields use form language', function () {
        $this->postJson('/api/v1/financial/recurring-transactions', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Enter the next due date.')
            ->assertJsonPath('errors.frequency.0', 'Choose how often this repeats.')
            ->assertJsonPath('errors.interval.0', 'Enter how many periods to wait between occurrences.')
            ->assertJsonPath('errors.postings.0', 'Add at least two postings.');
    });

    test('an end date before the next due date is rejected', function () {
        $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload(['ends_on' => '2026-09-01']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ends_on.0', 'The end date must not precede the next due date.');
    });

    test('non-base-currency postings are rejected', function () {
        $fbtc = Commodity::factory()->create();

        $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload([
            'postings' => [
                scheduleLeg($this->checking, $this->usd, '-100'),
                scheduleLeg($this->mortgageFund, $fbtc, '100'),
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['postings.1.financial_commodity_id' => 'Scheduled postings must use the base currency.']);
    });

    test('unbalanced templates are rejected', function () {
        $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload([
            'postings' => [
                scheduleLeg($this->checking, $this->usd, '-100'),
                scheduleLeg($this->mortgageFund, $this->usd, '90'),
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.postings.0', 'Postings must balance at cost.');
    });

    test('lead days are validated and round trip', function () {
        $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload(['lead_days' => -1]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.lead_days.0', 'Lead days may not be negative.');

        $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload([
            'date' => '2026-09-30',
            'lead_days' => 30,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.lead_days', 30)
            ->assertJsonPath('data.next_due_on', '2026-10-30')
            ->assertJsonPath('posted.0.date', '2026-09-30');
    });

    test('a schedule outside the lead window is stored without posting anything', function () {
        $payee = Payee::factory()->create();

        $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload([
            'date' => '2026-09-30',
            'financial_payee_id' => $payee->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payee.id', $payee->id)
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.starts_on', '2026-09-30')
            ->assertJsonPath('data.next_due_on', '2026-09-30')
            ->assertJsonPath('data.ends_on', null)
            ->assertJsonPath('data.lead_days', null)
            ->assertJsonPath('data.postings.0.position', 0)
            ->assertJsonPath('data.postings.0.status', 'pending')
            ->assertJsonPath('data.postings.0.amount', '-1712.77')
            ->assertJsonPath('posted', []);

        expect(Transaction::query()->count())->toBe(0);
    });

    test('a past start date backfills through the window and advances the pointer', function () {
        $response = $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload([
            'date' => '2026-07-31',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.next_due_on', '2026-09-30')
            ->assertJsonPath('posted.0.date', '2026-07-31')
            ->assertJsonPath('posted.1.date', '2026-08-31')
            ->assertJsonPath('posted.1.status', 'pending')
            ->assertJsonPath('posted.1.postings.0.amount', '-1712.77');

        expect($response->json('posted'))->toHaveCount(2);

        $scheduleId = $response->json('data.id');

        expect(Transaction::query()->where('financial_recurring_transaction_id', $scheduleId)->count())->toBe(2);

        $this->getJson('/api/v1/financial/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.financial_recurring_transaction_id', $scheduleId);
    });

    test('preview lists the dates a save would post without persisting', function () {
        $this->postJson('/api/v1/financial/recurring-transactions/preview', mortgageTransferPayload([
            'date' => '2026-08-21',
            'frequency' => 'weekly',
            'interval' => 1,
        ]))
            ->assertOk()
            ->assertExactJson(['data' => ['due_dates' => ['2026-08-21', '2026-08-28', '2026-09-04', '2026-09-11', '2026-09-18']]]);

        expect(RecurringTransaction::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });

    test('preview validates like a save', function () {
        $this->postJson('/api/v1/financial/recurring-transactions/preview', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Enter the next due date.');
    });

    test('preview for an existing schedule keeps its month-end anchor when the date is unchanged', function () {
        $schedule = RecurringTransaction::factory()->startingOn('2026-08-31')->create(['next_due_on' => '2026-09-30']);

        $this->postJson("/api/v1/financial/recurring-transactions/preview/{$schedule->id}", mortgageTransferPayload([
            'date' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertJsonPath('data.due_dates', []);

        CarbonImmutable::setTestNow('2026-10-20');

        $this->postJson("/api/v1/financial/recurring-transactions/preview/{$schedule->id}", mortgageTransferPayload([
            'date' => '2026-09-30',
        ]))
            ->assertOk()
            ->assertJsonPath('data.due_dates', ['2026-09-30', '2026-10-31']);
    });

    test('index lists schedules soonest first with templates', function () {
        $later = RecurringTransaction::factory()->startingOn('2026-12-01')->create();
        $sooner = RecurringTransaction::factory()->startingOn('2026-10-01')->create();
        RecurringPosting::factory()->forSchedule($sooner, 0)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => '-5']);
        RecurringPosting::factory()->forSchedule($sooner, 1)->inAccount($this->income)->ofCommodity($this->usd)->create(['amount' => '5']);

        $this->getJson('/api/v1/financial/recurring-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $sooner->id)
            ->assertJsonPath('data.0.postings.1.account.id', $this->income->id)
            ->assertJsonPath('data.0.postings.1.status', null)
            ->assertJsonPath('data.1.id', $later->id);
    });

    test('updating replaces the templates and posts what the new date makes due', function () {
        $schedule = RecurringTransaction::factory()->startingOn('2026-11-15')->create();
        RecurringPosting::factory()->forSchedule($schedule, 0)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => '-5']);
        RecurringPosting::factory()->forSchedule($schedule, 1)->inAccount($this->income)->ofCommodity($this->usd)->create(['amount' => '5']);

        $this->putJson("/api/v1/financial/recurring-transactions/{$schedule->id}", mortgageTransferPayload([
            'date' => '2026-09-12',
            'frequency' => 'weekly',
            'interval' => 2,
            'ends_on' => '2026-09-26',
        ]))
            ->assertOk()
            ->assertJsonPath('data.starts_on', '2026-09-12')
            ->assertJsonPath('data.next_due_on', '2026-09-26')
            ->assertJsonPath('data.ends_on', '2026-09-26')
            ->assertJsonPath('data.postings.0.amount', '-1712.77')
            ->assertJsonPath('posted.0.date', '2026-09-12')
            ->assertJsonCount(1, 'posted');

        expect($schedule->postings()->count())->toBe(2)
            ->and(Transaction::query()->where('financial_recurring_transaction_id', $schedule->id)->count())->toBe(1);
    });

    test('updating without changing the date keeps the anchor', function () {
        $schedule = RecurringTransaction::factory()->startingOn('2026-08-31')->create(['next_due_on' => '2026-11-30']);
        RecurringPosting::factory()->forSchedule($schedule, 0)->inAccount($this->checking)->ofCommodity($this->usd)->create(['amount' => '-5']);
        RecurringPosting::factory()->forSchedule($schedule, 1)->inAccount($this->income)->ofCommodity($this->usd)->create(['amount' => '5']);

        $this->putJson("/api/v1/financial/recurring-transactions/{$schedule->id}", mortgageTransferPayload([
            'date' => '2026-11-30',
            'memo' => 'Renamed',
        ]))
            ->assertOk()
            ->assertJsonPath('data.memo', 'Renamed')
            ->assertJsonPath('data.starts_on', '2026-08-31')
            ->assertJsonPath('data.next_due_on', '2026-11-30');
    });

    test('deleting a schedule keeps its posted transactions', function () {
        $response = $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload(['date' => '2026-09-12']))
            ->assertCreated();
        $scheduleId = $response->json('data.id');
        $transactionId = $response->json('posted.0.id');

        $this->deleteJson("/api/v1/financial/recurring-transactions/{$scheduleId}")->assertNoContent();

        expect(RecurringTransaction::query()->find($scheduleId))->toBeNull()
            ->and(RecurringPosting::query()->count())->toBe(0)
            ->and(Transaction::query()->findOrFail($transactionId)->financial_recurring_transaction_id)->toBeNull();
    });

    test('editing a posted transaction keeps its schedule link', function () {
        $response = $this->postJson('/api/v1/financial/recurring-transactions', mortgageTransferPayload(['date' => '2026-09-12']))
            ->assertCreated();
        $transaction = Transaction::query()->findOrFail($response->json('posted.0.id'));

        $this->putJson("/api/v1/financial/transactions/{$transaction->id}", [
            'date' => '2026-09-12',
            'memo' => 'Paid early',
            'postings' => [
                scheduleLeg($this->checking, $this->usd, '-1712.77', 'cleared'),
                scheduleLeg($this->mortgageFund, $this->usd, '1712.77', 'cleared'),
            ],
        ])->assertOk()
            ->assertJsonPath('data.financial_recurring_transaction_id', $response->json('data.id'));
    });
});
