<?php

use App\Enums\Financial\AccountType;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Posting;
use App\Support\Financial\SimpleFin\SimpleFinImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function bridgeAccount(string $id, array $transactions, string $balance = '100.00'): array
{
    return [
        'org' => ['domain' => 'example.bank', 'name' => 'Example Bank'],
        'id' => $id,
        'name' => 'Checking',
        'currency' => 'USD',
        'balance' => $balance,
        'available-balance' => $balance,
        'balance-date' => CarbonImmutable::parse('2026-09-10 23:30', 'America/Indiana/Indianapolis')->getTimestamp(),
        'transactions' => $transactions,
    ];
}

function bridgeTransaction(string $id, string $postedLocal, string $amount, string $description, bool $pending = false): array
{
    return [
        'id' => $id,
        'posted' => CarbonImmutable::parse($postedLocal, 'America/Indiana/Indianapolis')->getTimestamp(),
        'amount' => $amount,
        'description' => $description,
        'payee' => 'Kroger',
        'memo' => '',
        'pending' => $pending,
    ];
}

beforeEach(function () {
    config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
    config()->set('financial.simplefin.timezone', 'America/Indiana/Indianapolis');
    CarbonImmutable::setTestNow('2026-09-11 03:00:00');

    $this->checking = Account::factory()->ofType(AccountType::Asset)->create(['simplefin_account_id' => 'ACT-1']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('mapped accounts gain bank rows dated in the local timezone, plus a balance snapshot', function () {
    Http::fake([
        'bridge.example/simplefin/accounts*' => Http::response(['errors' => [], 'accounts' => [
            bridgeAccount('ACT-1', [
                bridgeTransaction('T-1', '2026-09-09 22:45', '-42.10', 'KROGER #123'),
                bridgeTransaction('T-2', '2026-09-10 08:00', '1500.00', 'PAYROLL', pending: true),
            ], '2345.67'),
            bridgeAccount('ACT-9', [bridgeTransaction('T-9', '2026-09-10 08:00', '-1', 'ELSEWHERE')]),
        ]]),
    ]);

    $result = app(SimpleFinImporter::class)->import();

    expect($result)->toMatchArray(['created' => 2, 'updated' => 0, 'skipped' => ['Example Bank · Checking'], 'errors' => []]);

    $rows = BankTransaction::query()->orderBy('external_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->financial_account_id)->toBe($this->checking->id)
        ->and($rows[0]->posted_on->toDateString())->toBe('2026-09-09')
        ->and($rows[0]->amount->isEqualTo('-42.1'))->toBeTrue()
        ->and($rows[0]->description)->toBe('KROGER #123')
        ->and($rows[0]->payee)->toBe('Kroger')
        ->and($rows[0]->pending)->toBeFalse()
        ->and($rows[0]->payload['id'])->toBe('T-1')
        ->and($rows[1]->pending)->toBeTrue();

    $account = $this->checking->fresh();

    expect((string) $account->simplefin_balance->strippedOfTrailingZeros())->toBe('2345.67')
        ->and($account->simplefin_balance_date->toDateString())->toBe('2026-09-10')
        ->and($account->simplefin_synced_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'pending=1')
        && str_contains($request->url(), 'start-date='.CarbonImmutable::parse('2026-08-11', 'America/Indiana/Indianapolis')->getTimestamp()));
});

test('a re-read refreshes rows in place so pending becomes posted and links survive', function () {
    $row = BankTransaction::factory()->inAccount($this->checking)->create([
        'external_id' => 'T-2',
        'pending' => true,
        'posted_on' => '2026-09-10',
        'amount' => '1500',
    ]);
    $posting = Posting::factory()->inAccount($this->checking)->create(['amount' => '1500']);
    $row->update(['financial_posting_id' => $posting->id]);

    Http::fake([
        'bridge.example/*' => Http::response(['errors' => [], 'accounts' => [
            bridgeAccount('ACT-1', [bridgeTransaction('T-2', '2026-09-11 00:30', '1500.00', 'PAYROLL DEP')]),
        ]]),
    ]);

    $result = app(SimpleFinImporter::class)->import();

    expect($result['created'])->toBe(0)->and($result['updated'])->toBe(1)
        ->and(BankTransaction::query()->count())->toBe(1);

    $row->refresh();

    expect($row->pending)->toBeFalse()
        ->and($row->posted_on->toDateString())->toBe('2026-09-11')
        ->and($row->description)->toBe('PAYROLL DEP')
        ->and($row->financial_posting_id)->toBe($posting->id);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'start-date='.CarbonImmutable::parse('2026-09-03', 'America/Indiana/Indianapolis')->getTimestamp()));
});

test('the command reports counts and an explicit day window', function () {
    Http::fake(['bridge.example/*' => Http::response(['errors' => ['Bank needs attention'], 'accounts' => [
        bridgeAccount('ACT-1', [bridgeTransaction('T-1', '2026-09-01 12:00', '-5', 'COFFEE')]),
    ]])]);

    $this->artisan('financial:simplefin-import', ['--days' => 90])
        ->expectsOutputToContain('Bank needs attention')
        ->expectsOutputToContain('1 new, 0 refreshed.')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'start-date='.CarbonImmutable::parse('2026-06-12', 'America/Indiana/Indianapolis')->getTimestamp()));
});

test('the command is a no-op without an access url', function () {
    config()->set('services.simplefin.access_url', null);
    Http::fake();

    $this->artisan('financial:simplefin-import')
        ->expectsOutputToContain('SimpleFIN is not configured; nothing to import.')
        ->assertSuccessful();

    Http::assertNothingSent();
});
