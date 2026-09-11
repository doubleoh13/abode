<?php

namespace App\Support\Financial\SimpleFin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to a claimed SimpleFIN Bridge access URL. The URL carries basic-auth
 * credentials, so it is never logged or echoed.
 */
class SimpleFinClient
{
    private const string ACCOUNTS_CACHE_KEY = 'simplefin.accounts';

    private const int ACCOUNTS_CACHE_SECONDS = 3600;

    /**
     * The account list, served from cache for an hour so the account form
     * opens without a bridge round trip.
     *
     * @return array{accounts: list<SimpleFinAccount>, errors: list<string>}
     */
    public function cachedAccounts(): array
    {
        return Cache::remember(self::ACCOUNTS_CACHE_KEY, self::ACCOUNTS_CACHE_SECONDS, fn (): array => $this->accounts());
    }

    /**
     * Fetch the account list now and replace the cached copy.
     */
    public function refreshAccountsCache(): void
    {
        Cache::put(self::ACCOUNTS_CACHE_KEY, $this->accounts(), self::ACCOUNTS_CACHE_SECONDS);
    }

    public function isConfigured(): bool
    {
        return is_string(config('services.simplefin.access_url')) && config('services.simplefin.access_url') !== '';
    }

    /**
     * Every account the bridge exposes, balances only.
     *
     * @return array{accounts: list<SimpleFinAccount>, errors: list<string>}
     */
    public function accounts(): array
    {
        $payload = $this->get(['balances-only' => 1]);

        return [
            'accounts' => array_map(SimpleFinAccount::fromPayload(...), $payload['accounts'] ?? []),
            'errors' => array_values(array_map(strval(...), $payload['errors'] ?? [])),
        ];
    }

    /**
     * One account with its transactions posted on or after the start date,
     * pending ones included. Null when the bridge no longer lists the account.
     */
    public function accountTransactions(string $simpleFinAccountId, CarbonImmutable $startDate): ?SimpleFinAccount
    {
        $payload = $this->get([
            'account' => $simpleFinAccountId,
            'start-date' => $startDate->startOfDay()->getTimestamp(),
            'pending' => 1,
        ]);

        foreach ($payload['accounts'] ?? [] as $account) {
            if ((string) $account['id'] === $simpleFinAccountId) {
                return SimpleFinAccount::fromPayload($account);
            }
        }

        return null;
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function get(array $query): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SimpleFIN is not configured. Set SIMPLEFIN_ACCESS_URL.');
        }

        $parts = parse_url((string) config('services.simplefin.access_url'));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) {
            throw new RuntimeException('SIMPLEFIN_ACCESS_URL must be a claimed access URL with credentials.');
        }

        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');

        $response = Http::withBasicAuth(urldecode($parts['user']), urldecode($parts['pass']))
            ->acceptJson()
            ->timeout(60)
            ->get(rtrim($base, '/').'/accounts', $query);

        if ($response->failed()) {
            throw new RuntimeException("SimpleFIN request failed with HTTP {$response->status()}.");
        }

        return $response->json();
    }
}
