<?php

namespace App\Support\Financial;

use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists schedules with their posting templates and immediately posts
 * whatever the new pointer makes due.
 */
class RecurringTransactionWriter
{
    public function __construct(private readonly RecurringTransactionMaterializer $materializer) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{schedule: RecurringTransaction, posted: list<Transaction>}
     */
    public function store(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $schedule = RecurringTransaction::query()->create([
                ...$this->scheduleAttributes($data),
                'starts_on' => $data['date'],
                'next_due_on' => $data['date'],
            ]);

            $this->replacePostings($schedule, $data['postings']);

            return ['schedule' => $schedule, 'posted' => $this->materializer->materialize($schedule)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{schedule: RecurringTransaction, posted: list<Transaction>}
     */
    public function update(RecurringTransaction $schedule, array $data): array
    {
        return DB::transaction(function () use ($schedule, $data): array {
            $schedule->update([
                ...$this->scheduleAttributes($data),
                ...$this->pointerAttributes($schedule, $data['date']),
            ]);

            $schedule->postings()->delete();
            $this->replacePostings($schedule, $data['postings']);

            return ['schedule' => $schedule, 'posted' => $this->materializer->materialize($schedule)];
        });
    }

    /**
     * The schedule as it would stand after saving, for previewing what a
     * save would post. Nothing is persisted.
     *
     * @param  array<string, mixed>  $data
     */
    public function draft(array $data, ?RecurringTransaction $existing = null): RecurringTransaction
    {
        $pointer = $existing === null
            ? ['starts_on' => $data['date'], 'next_due_on' => $data['date']]
            : $this->pointerAttributes($existing, $data['date']);

        return new RecurringTransaction([
            ...($existing?->only(['starts_on', 'next_due_on']) ?? []),
            ...$this->scheduleAttributes($data),
            ...$pointer,
        ]);
    }

    public function destroy(RecurringTransaction $schedule): void
    {
        $schedule->delete();
    }

    /**
     * The form's date is "when the next one is due": a changed date becomes
     * both the new pointer and the new anchor, while an unchanged date leaves
     * the original anchor so month-end clamping keeps its reference day.
     *
     * @return array<string, mixed>
     */
    private function pointerAttributes(RecurringTransaction $schedule, string $date): array
    {
        if (CarbonImmutable::parse($date)->equalTo($schedule->next_due_on)) {
            return [];
        }

        return ['starts_on' => $date, 'next_due_on' => $date];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scheduleAttributes(array $data): array
    {
        return [
            'financial_payee_id' => $data['financial_payee_id'] ?? null,
            'memo' => $data['memo'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'frequency' => $data['frequency'],
            'interval' => $data['interval'],
            'ends_on' => $data['ends_on'] ?? null,
            'lead_days' => $data['lead_days'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $postingPayloads
     */
    private function replacePostings(RecurringTransaction $schedule, array $postingPayloads): void
    {
        foreach (array_values($postingPayloads) as $position => $payload) {
            $schedule->postings()->create([
                'position' => $position,
                'status' => $payload['status'] ?? null,
                'financial_account_id' => $payload['financial_account_id'],
                'financial_commodity_id' => $payload['financial_commodity_id'],
                'amount' => $payload['amount'],
                'memo' => $payload['memo'] ?? null,
                'metadata' => $payload['metadata'] ?? [],
            ]);
        }
    }
}
