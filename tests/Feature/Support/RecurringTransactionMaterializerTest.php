<?php

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Enums\Financial\RecurrenceFrequency;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\RecurringPosting;
use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use App\Support\Financial\RecurringTransactionMaterializer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10');
    config()->set('financial.recurring_lead_days', 14);

    $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
    $this->receivable = Account::factory()->ofType(AccountType::Asset)->create();
    $this->income = Account::factory()->ofType(AccountType::Income)->create();

    $this->schedule = function (RecurrenceFrequency $frequency, string $startsOn, int $interval = 1, ?string $endsOn = null) use ($usd): RecurringTransaction {
        $schedule = RecurringTransaction::factory()->every($frequency, $interval)->startingOn($startsOn)->create([
            'memo' => 'Child support',
            'ends_on' => $endsOn,
        ]);
        RecurringPosting::factory()->forSchedule($schedule, 0)->inAccount($this->receivable)->ofCommodity($usd)
            ->create(['amount' => '115', 'status' => PostingStatus::Cleared]);
        RecurringPosting::factory()->forSchedule($schedule, 1)->inAccount($this->income)->ofCommodity($usd)
            ->create(['amount' => '-115', 'memo' => 'weekly']);

        return $schedule;
    };
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('a weekly schedule posts every occurrence through the window with the template statuses', function () {
    $schedule = ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-04');

    $posted = app(RecurringTransactionMaterializer::class)->materialize($schedule);

    expect(array_map(fn (Transaction $transaction): string => $transaction->date->toDateString(), $posted))
        ->toBe(['2026-09-04', '2026-09-11', '2026-09-18'])
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2026-09-25');

    $first = $posted[0]->load('postings');

    expect($first->memo)->toBe('Child support')
        ->and($first->financial_recurring_transaction_id)->toBe($schedule->id)
        ->and($first->postings[0]->status)->toBe(PostingStatus::Cleared)
        ->and((string) $first->postings[0]->amount)->toBe('115')
        ->and($first->postings[1]->status)->toBeNull()
        ->and($first->postings[1]->memo)->toBe('weekly');
});

test('running again posts nothing until the window moves', function () {
    $schedule = ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-04');
    $materializer = app(RecurringTransactionMaterializer::class);

    $materializer->materialize($schedule);

    expect($materializer->materialize($schedule->fresh()))->toBe([]);

    CarbonImmutable::setTestNow('2026-09-12');

    expect($materializer->materialize($schedule->fresh()))->toHaveCount(1)
        ->and(Transaction::query()->count())->toBe(4);
});

test('deleting a posted occurrence does not rewind the pointer', function () {
    $schedule = ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-04');
    $materializer = app(RecurringTransactionMaterializer::class);

    $posted = $materializer->materialize($schedule);
    $posted[1]->delete();

    expect($materializer->materialize($schedule->fresh()))->toBe([])
        ->and(Transaction::query()->pluck('date')->map->toDateString()->all())->toBe(['2026-09-04', '2026-09-18']);
});

test('monthly occurrences clamp to month end without drifting', function () {
    CarbonImmutable::setTestNow('2027-01-20');
    $schedule = ($this->schedule)(RecurrenceFrequency::Monthly, '2026-08-31');

    $posted = app(RecurringTransactionMaterializer::class)->materialize($schedule);

    expect(array_map(fn (Transaction $transaction): string => $transaction->date->toDateString(), $posted))
        ->toBe(['2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31'])
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2027-02-28');
});

test('a daily schedule posts one occurrence per day', function () {
    $schedule = ($this->schedule)(RecurrenceFrequency::Daily, '2026-09-22');

    $posted = app(RecurringTransactionMaterializer::class)->materialize($schedule);

    expect(array_map(fn (Transaction $transaction): string => $transaction->date->toDateString(), $posted))
        ->toBe(['2026-09-22', '2026-09-23', '2026-09-24'])
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2026-09-25');
});

test('the interval multiplies the frequency', function () {
    CarbonImmutable::setTestNow('2026-12-01');
    $schedule = ($this->schedule)(RecurrenceFrequency::Monthly, '2026-09-15', interval: 3);

    $posted = app(RecurringTransactionMaterializer::class)->materialize($schedule);

    expect(array_map(fn (Transaction $transaction): string => $transaction->date->toDateString(), $posted))
        ->toBe(['2026-09-15', '2026-12-15'])
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2027-03-15');
});

test('an end date stops posting and leaves the pointer past it', function () {
    $schedule = ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-04', endsOn: '2026-09-12');

    $posted = app(RecurringTransactionMaterializer::class)->materialize($schedule);

    expect(array_map(fn (Transaction $transaction): string => $transaction->date->toDateString(), $posted))
        ->toBe(['2026-09-04', '2026-09-11'])
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2026-09-18');
});

test('a zero lead window posts only on the due date', function () {
    config()->set('financial.recurring_lead_days', 0);
    $schedule = ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-10');

    expect(app(RecurringTransactionMaterializer::class)->materialize($schedule))->toHaveCount(1)
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2026-09-17');
});

test('a schedule-level lead window overrides the default', function () {
    $schedule = ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-10');
    $schedule->update(['lead_days' => 0]);

    expect(app(RecurringTransactionMaterializer::class)->materialize($schedule))->toHaveCount(1);

    $schedule->update(['lead_days' => 30]);

    expect(app(RecurringTransactionMaterializer::class)->materialize($schedule->fresh()))->toHaveCount(4)
        ->and($schedule->fresh()->next_due_on->toDateString())->toBe('2026-10-15');
});

test('the command posts every schedule and reports the total', function () {
    ($this->schedule)(RecurrenceFrequency::Weekly, '2026-09-04');
    ($this->schedule)(RecurrenceFrequency::Monthly, '2026-12-01');

    $this->artisan('financial:post-recurring')
        ->expectsOutputToContain('Child support: 3 posted')
        ->expectsOutputToContain('3 transaction(s) posted.')
        ->assertSuccessful();

    expect(Transaction::query()->count())->toBe(3);
});
