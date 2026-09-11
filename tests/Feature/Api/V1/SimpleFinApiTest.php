<?php

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function simpleFinAccountPayload(string $id, string $name, string $balance, array $transactions = []): array
{
    return [
        'org' => ['domain' => 'example.bank', 'name' => 'Example Bank', 'sfin-url' => 'https://example.bank/sfin'],
        'id' => $id,
        'name' => $name,
        'currency' => 'USD',
        'balance' => $balance,
        'available-balance' => $balance,
        'balance-date' => CarbonImmutable::parse('2026-08-26 12:00:00', 'UTC')->timestamp,
        'transactions' => $transactions,
    ];
}

function simpleFinTransactionPayload(string $id, string $postedAt, string $amount, string $description, bool $pending = false): array
{
    return [
        'id' => $id,
        'posted' => CarbonImmutable::parse($postedAt, 'UTC')->timestamp,
        'amount' => $amount,
        'description' => $description,
        'payee' => 'Kroger',
        'memo' => '',
        'pending' => $pending,
    ];
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/simplefin/accounts')->assertUnauthorized();
});

test('a viewer cannot list SimpleFIN accounts', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->getJson('/api/v1/financial/simplefin/accounts')->assertForbidden();
});

test('a viewer cannot sync an account', function () {
    actingWithPermissions(Permission::ViewFinances);
    $account = Account::factory()->create(['simplefin_account_id' => 'ACT-1']);

    $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")->assertForbidden();
});

describe('with finance permissions', function () {
    beforeEach(function () {
        actingWithPermissions(Permission::ViewFinances, Permission::ManageFinances);
        Cache::flush();
    });

    test('an unconfigured bridge reports so without calling out', function () {
        config()->set('services.simplefin.access_url', null);
        Http::fake();

        $this->getJson('/api/v1/financial/simplefin/accounts')
            ->assertOk()
            ->assertExactJson(['data' => [], 'configured' => false, 'errors' => []]);

        Http::assertNothingSent();
    });

    test('accounts are listed with balances and bridge errors, and cached', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');

        Http::fake([
            'bridge.example/simplefin/accounts*' => Http::response([
                'errors' => ['Example Bank needs re-authentication'],
                'accounts' => [
                    simpleFinAccountPayload('ACT-1', 'Everyday Checking', '1234.56'),
                    simpleFinAccountPayload('ACT-2', 'Rewards Visa', '-89.10'),
                ],
            ]),
        ]);

        $this->getJson('/api/v1/financial/simplefin/accounts')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('errors.0', 'Example Bank needs re-authentication')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => 'ACT-1',
                'organization' => 'Example Bank',
                'name' => 'Everyday Checking',
                'currency' => 'USD',
                'balance' => '1234.56',
                'balance_date' => '2026-08-26',
            ])
            ->assertJsonPath('data.1.balance', '-89.10');

        $this->getJson('/api/v1/financial/simplefin/accounts')->assertOk()->assertJsonCount(2, 'data');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret'))
                && str_contains($request->url(), 'balances-only=1');
        });
    });

    test('syncing an unmapped account is a conflict', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        Http::fake();
        $account = Account::factory()->create();

        $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")
            ->assertConflict()
            ->assertJsonPath('message', 'The account is not mapped to a SimpleFIN account.');

        Http::assertNothingSent();
    });

    test('syncing without a configured bridge is a conflict', function () {
        config()->set('services.simplefin.access_url', null);
        Http::fake();
        $account = Account::factory()->create(['simplefin_account_id' => 'ACT-1']);

        $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")
            ->assertConflict()
            ->assertJsonPath('message', 'SimpleFIN is not configured.');

        Http::assertNothingSent();
    });

    test('a first sync stages the initial window of bank transactions and records the bank balance', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        config()->set('financial.simplefin.initial_days', 30);
        CarbonImmutable::setTestNow('2026-09-11 15:00:00');
        $account = Account::factory()->create(['simplefin_account_id' => 'ACT-1']);

        Http::fake([
            'bridge.example/simplefin/accounts*' => Http::response([
                'errors' => [],
                'accounts' => [
                    simpleFinAccountPayload('ACT-1', 'Everyday Checking', '620.00', [
                        // 03:30 UTC on the 10th is still the 9th in Indiana.
                        simpleFinTransactionPayload('TX-1', '2026-09-10 03:30:00', '-42.10', 'KROGER #123'),
                        simpleFinTransactionPayload('TX-2', '2026-09-11 12:00:00', '-5.00', 'PENDING COFFEE', pending: true),
                    ]),
                ],
            ]),
        ]);

        $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")
            ->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.simplefin_synced_at', '2026-09-11T15:00:00+00:00')
            ->assertJsonPath('data.simplefin_balance', '620')
            ->assertJsonPath('data.simplefin_balance_date', '2026-08-26');

        Http::assertSent(function ($request): bool {
            $startDate = CarbonImmutable::parse('2026-08-12', 'America/Indiana/Indianapolis')->timestamp;

            return $request['account'] === 'ACT-1'
                && (int) $request['start-date'] === $startDate
                && (int) $request['pending'] === 1;
        });

        $rows = BankTransaction::query()->where('financial_account_id', $account->id)->orderBy('external_id')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->external_id)->toBe('TX-1')
            ->and($rows[0]->posted_on->toDateString())->toBe('2026-09-09')
            ->and((string) $rows[0]->amount->strippedOfTrailingZeros())->toBe('-42.1')
            ->and($rows[0]->description)->toBe('KROGER #123')
            ->and($rows[0]->pending)->toBeFalse()
            ->and($rows[0]->financial_posting_id)->toBeNull()
            ->and($rows[1]->pending)->toBeTrue();
    });

    test('a later sync re-reads the overlap window and settles pending rows in place', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        config()->set('financial.simplefin.overlap_days', 7);
        $account = Account::factory()->create(['simplefin_account_id' => 'ACT-1']);
        $pending = BankTransaction::factory()->inAccount($account)->create([
            'external_id' => 'TX-2',
            'posted_on' => '2026-09-08',
            'pending' => true,
            'amount' => '-5.00',
            'description' => 'PENDING COFFEE',
        ]);

        Http::fake([
            'bridge.example/simplefin/accounts*' => Http::response([
                'errors' => [],
                'accounts' => [
                    simpleFinAccountPayload('ACT-1', 'Everyday Checking', '615.00', [
                        simpleFinTransactionPayload('TX-2', '2026-09-09 16:00:00', '-5.25', 'COFFEE SHOP'),
                        simpleFinTransactionPayload('TX-3', '2026-09-10 16:00:00', '-12.00', 'LUNCH'),
                    ]),
                ],
            ]),
        ]);

        $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 1);

        Http::assertSent(fn ($request): bool => (int) $request['start-date'] === CarbonImmutable::parse('2026-09-01', 'America/Indiana/Indianapolis')->timestamp);

        $pending->refresh();

        expect(BankTransaction::query()->count())->toBe(2)
            ->and($pending->pending)->toBeFalse()
            ->and($pending->posted_on->toDateString())->toBe('2026-09-09')
            ->and((string) $pending->amount->strippedOfTrailingZeros())->toBe('-5.25')
            ->and($pending->description)->toBe('COFFEE SHOP');
    });

    test('a matched row settling at the bank clears a pending posting but leaves reconciled and cleared ones alone', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $account = Account::factory()->ofType(AccountType::Asset)->create(['simplefin_account_id' => 'ACT-1']);
        $expense = Account::factory()->ofType(AccountType::Expense)->create();

        $legs = [];

        foreach (['TX-P' => PostingStatus::Pending, 'TX-R' => PostingStatus::Reconciled, 'TX-C' => PostingStatus::Cleared] as $externalId => $status) {
            $transaction = Transaction::factory()->on('2026-09-08')->create();
            Posting::factory()->forTransaction($transaction, 1)->inAccount($expense)->ofCommodity($usd)->create(['amount' => '5']);
            $legs[$externalId] = Posting::factory()->forTransaction($transaction, 0)->inAccount($account)->ofCommodity($usd)->create(['amount' => '-5', 'status' => $status]);
            BankTransaction::factory()->linkedTo($legs[$externalId])->create(['external_id' => $externalId, 'posted_on' => '2026-09-08', 'pending' => true]);
        }

        Http::fake([
            'bridge.example/simplefin/accounts*' => Http::response([
                'errors' => [],
                'accounts' => [
                    simpleFinAccountPayload('ACT-1', 'Everyday Checking', '100.00', [
                        simpleFinTransactionPayload('TX-P', '2026-09-09 16:00:00', '-5.00', 'SETTLED'),
                        simpleFinTransactionPayload('TX-R', '2026-09-09 16:00:00', '-5.00', 'SETTLED'),
                        simpleFinTransactionPayload('TX-C', '2026-09-09 16:00:00', '-5.00', 'SETTLED', pending: true),
                    ]),
                ],
            ]),
        ]);

        $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")->assertOk()->assertJsonPath('updated', 3);

        expect($legs['TX-P']->refresh()->status)->toBe(PostingStatus::Cleared)
            ->and($legs['TX-R']->refresh()->status)->toBe(PostingStatus::Reconciled)
            ->and($legs['TX-C']->refresh()->status)->toBe(PostingStatus::Cleared)
            ->and(BankTransaction::query()->where('external_id', 'TX-P')->value('financial_posting_id'))->toBe($legs['TX-P']->id);
    });

    test('rows matching an ignored description pattern are never staged', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        config()->set('financial.simplefin.ignored_description_patterns', ['/CORE ACCOUNT FIDELITY GOVERNMENT MONEY MARKET/i', '/^REINVESTMENT FIDELITY/i']);
        $account = Account::factory()->create(['simplefin_account_id' => 'ACT-1']);

        Http::fake([
            'bridge.example/simplefin/accounts*' => Http::response([
                'errors' => [],
                'accounts' => [
                    simpleFinAccountPayload('ACT-1', 'Cash Management', '100.00', [
                        simpleFinTransactionPayload('TX-1', '2026-09-08 16:00:00', '-10.99', 'DIRECT DEBIT East Allen Cou9164674700 (Cash)'),
                        simpleFinTransactionPayload('TX-2', '2026-09-08 16:00:00', '10.99', 'REDEMPTION FROM CORE ACCOUNT FIDELITY GOVERNMENT MONEY MARKET (SPAXX) MORNING TRADE (Cash)'),
                        simpleFinTransactionPayload('TX-3', '2026-08-31 16:00:00', '37.66', 'DIVIDEND RECEIVED FIDELITY GOVERNMENT MONEY MARKET (SPAXX) (Cash)'),
                        simpleFinTransactionPayload('TX-4', '2026-08-31 16:00:00', '-37.66', 'REINVESTMENT FIDELITY GOVERNMENT MONEY MARKET (SPAXX) (Cash)'),
                    ]),
                ],
            ]),
        ]);

        $this->postJson("/api/v1/financial/accounts/{$account->id}/simplefin-sync")
            ->assertOk()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('ignored', 2);

        expect(BankTransaction::query()->where('financial_account_id', $account->id)->orderBy('external_id')->pluck('external_id')->all())
            ->toBe(['TX-1', 'TX-3']);
    });

    test('a failing bridge surfaces as a server error, not a silent empty list', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        Http::fake(['bridge.example/*' => Http::response('nope', 502)]);

        $this->withoutExceptionHandling()
            ->getJson('/api/v1/financial/simplefin/accounts');
    })->throws(RuntimeException::class, 'SimpleFIN request failed with HTTP 502.');
});
