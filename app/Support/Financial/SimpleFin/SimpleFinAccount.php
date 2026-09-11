<?php

namespace App\Support\Financial\SimpleFin;

use Carbon\CarbonImmutable;

final readonly class SimpleFinAccount
{
    public function __construct(
        public string $id,
        public string $organization,
        public string $name,
        public string $currency,
        public string $balance,
        public CarbonImmutable $balanceDate,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            organization: (string) ($payload['org']['name'] ?? $payload['org']['domain'] ?? ''),
            name: (string) $payload['name'],
            currency: (string) $payload['currency'],
            balance: (string) $payload['balance'],
            balanceDate: CarbonImmutable::createFromTimestampUTC((int) $payload['balance-date']),
        );
    }
}
