<?php

namespace App\Support\Financial\SimpleFin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to a claimed SimpleFIN Bridge access URL. The URL carries basic-auth
 * credentials, so it is never logged or echoed.
 */
class SimpleFinClient
{
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
     * Every account with its transactions posted on or after the start date,
     * pending ones included.
     *
     * @return array{accounts: list<SimpleFinAccount>, errors: list<string>}
     */
    public function transactions(CarbonImmutable $startDate): array
    {
        $payload = $this->get(['start-date' => $startDate->startOfDay()->getTimestamp(), 'pending' => 1]);

        return [
            'accounts' => array_map(SimpleFinAccount::fromPayload(...), $payload['accounts'] ?? []),
            'errors' => array_values(array_map(strval(...), $payload['errors'] ?? [])),
        ];
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
