<?php

use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('an unconfigured bridge is reported without calling out', function () {
    config()->set('services.simplefin.access_url', null);
    Http::fake();
    Account::factory()->create(['simplefin_account_id' => 'ACT-1']);

    $this->artisan('financial:simplefin-sync')
        ->expectsOutputToContain('SimpleFIN is not configured; nothing to sync.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('every mapped account is synced and a failing one does not stop the rest', function () {
    config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
    Cache::flush();
    $checking = Account::factory()->create(['name' => 'Checking', 'simplefin_account_id' => 'ACT-1']);
    $savings = Account::factory()->create(['name' => 'Savings', 'simplefin_account_id' => 'ACT-GONE']);
    Account::factory()->create(['name' => 'Cash']);

    Http::fake(function ($request) {
        $accountId = $request->data()['account'] ?? null;

        return Http::response([
            'errors' => [],
            'accounts' => $accountId === 'ACT-1' ? [
                [
                    'org' => ['name' => 'Example Bank'],
                    'id' => 'ACT-1',
                    'name' => 'Everyday Checking',
                    'currency' => 'USD',
                    'balance' => '100.00',
                    'balance-date' => CarbonImmutable::parse('2026-09-11 12:00:00', 'UTC')->timestamp,
                    'transactions' => [
                        ['id' => 'TX-1', 'posted' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC')->timestamp, 'amount' => '-4.50', 'description' => 'COFFEE', 'pending' => false],
                    ],
                ],
            ] : [],
        ]);
    });

    $this->artisan('financial:simplefin-sync')
        ->expectsOutputToContain("{$checking->path}: 1 new, 0 refreshed")
        ->expectsOutputToContain("{$savings->path}: SimpleFIN no longer lists the mapped account.")
        ->assertFailed();

    Http::assertSentCount(3);

    expect(Cache::has('simplefin.accounts'))->toBeTrue()
        ->and(BankTransaction::query()->where('financial_account_id', $checking->id)->count())->toBe(1)
        ->and($checking->refresh()->simplefin_synced_at)->not->toBeNull()
        ->and($savings->refresh()->simplefin_synced_at)->toBeNull();
});
