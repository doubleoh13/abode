<?php

use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function simpleFinAccountPayload(string $id, string $name, string $balance): array
{
    return [
        'org' => ['domain' => 'example.bank', 'name' => 'Example Bank', 'sfin-url' => 'https://example.bank/sfin'],
        'id' => $id,
        'name' => $name,
        'currency' => 'USD',
        'balance' => $balance,
        'available-balance' => $balance,
        'balance-date' => CarbonImmutable::parse('2026-08-26', 'UTC')->timestamp,
        'transactions' => [],
    ];
}

test('guests receive a 401', function () {
    $this->getJson('/api/v1/financial/simplefin/accounts')->assertUnauthorized();
});

test('a viewer cannot list SimpleFIN accounts', function () {
    actingWithPermissions(Permission::ViewFinances);

    $this->getJson('/api/v1/financial/simplefin/accounts')->assertForbidden();
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

    test('a failing bridge surfaces as a server error, not a silent empty list', function () {
        config()->set('services.simplefin.access_url', 'https://user:secret@bridge.example/simplefin');
        Http::fake(['bridge.example/*' => Http::response('nope', 502)]);

        $this->withoutExceptionHandling()
            ->getJson('/api/v1/financial/simplefin/accounts');
    })->throws(RuntimeException::class, 'SimpleFIN request failed with HTTP 502.');
});
