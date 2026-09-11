<?php

namespace App\Support\Financial\SimpleFin;

use Carbon\CarbonImmutable;

final readonly class SimpleFinTransaction
{
    public function __construct(
        public string $id,
        public CarbonImmutable $postedAt,
        public ?CarbonImmutable $transactedAt,
        public string $amount,
        public ?string $description,
        public ?string $payee,
        public ?string $memo,
        public bool $pending,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $transactedAt = $payload['transacted_at'] ?? null;

        return new self(
            id: (string) $payload['id'],
            postedAt: CarbonImmutable::createFromTimestampUTC((int) $payload['posted']),
            transactedAt: is_numeric($transactedAt) ? CarbonImmutable::createFromTimestampUTC((int) $transactedAt) : null,
            amount: (string) $payload['amount'],
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            payee: isset($payload['payee']) ? (string) $payload['payee'] : null,
            memo: isset($payload['memo']) ? (string) $payload['memo'] : null,
            pending: (bool) ($payload['pending'] ?? false),
            payload: $payload,
        );
    }
}
