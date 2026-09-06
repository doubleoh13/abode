<?php

namespace App\Support\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Persists transactions with their postings and lot lifecycle: postings sync
 * by id, lots are created or re-derived from acquisition legs, and lots left
 * without any referencing posting are deleted.
 */
class TransactionWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Transaction
    {
        return DB::transaction(function () use ($data): Transaction {
            $transaction = Transaction::query()->create($this->transactionAttributes($data));

            $this->syncPostings($transaction, $data['postings']);

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data): Transaction {
            $transaction->update($this->transactionAttributes($data));

            Posting::query()
                ->where('financial_transaction_id', $transaction->id)
                ->update(['position' => DB::raw('position + 10000')]);

            $this->syncPostings($transaction, $data['postings']);

            return $transaction;
        });
    }

    public function destroy(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $lotIds = $transaction->postings()->whereNotNull('financial_lot_id')->pluck('financial_lot_id')->all();

            $transaction->delete();

            $this->sweepOrphanedLots($lotIds);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function transactionAttributes(array $data): array
    {
        return [
            'date' => $data['date'],
            'financial_payee_id' => $data['financial_payee_id'] ?? null,
            'memo' => $data['memo'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $postingPayloads
     */
    private function syncPostings(Transaction $transaction, array $postingPayloads): void
    {
        $persistedPostings = $transaction->postings()->get()->keyBy('id');
        $baseCurrencyId = Commodity::baseCurrency()->id;
        $sweepCandidates = [];
        $keptIds = [];

        foreach (array_values($postingPayloads) as $position => $payload) {
            $persisted = isset($payload['id']) ? $persistedPostings->get((int) $payload['id']) : null;

            $attributes = [
                'position' => $position,
                'status' => $payload['status'],
                'financial_account_id' => $payload['financial_account_id'],
                'financial_commodity_id' => $payload['financial_commodity_id'],
                'financial_lot_id' => $this->resolveLot($transaction, $payload, $persisted, $baseCurrencyId, $sweepCandidates),
                'amount' => $payload['amount'],
                'memo' => $payload['memo'] ?? null,
                'metadata' => $payload['metadata'] ?? [],
            ];

            if ($persisted !== null) {
                $persisted->update($attributes);
                $keptIds[] = $persisted->id;
            } else {
                $transaction->postings()->create($attributes);
            }
        }

        foreach ($persistedPostings as $posting) {
            if (! in_array($posting->id, $keptIds, true)) {
                if ($posting->financial_lot_id !== null) {
                    $sweepCandidates[] = $posting->financial_lot_id;
                }

                $posting->delete();
            }
        }

        $this->sweepOrphanedLots($sweepCandidates);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $sweepCandidates
     */
    private function resolveLot(Transaction $transaction, array $payload, ?Posting $persisted, int $baseCurrencyId, array &$sweepCandidates): ?int
    {
        $previousLotId = $persisted?->financial_lot_id;

        if ((int) $payload['financial_commodity_id'] === $baseCurrencyId) {
            if ($previousLotId !== null) {
                $sweepCandidates[] = $previousLotId;
            }

            return null;
        }

        if (isset($payload['financial_lot_id'])) {
            $lotId = (int) $payload['financial_lot_id'];

            if ($previousLotId !== null && $previousLotId !== $lotId) {
                $sweepCandidates[] = $previousLotId;
            }

            return $lotId;
        }

        $lotAttributes = [
            'financial_commodity_id' => $payload['financial_commodity_id'],
            'acquired_at' => $payload['lot']['acquired_at'] ?? $transaction->date->toDateString(),
            'cost' => $payload['lot']['cost'],
        ];

        if ($previousLotId !== null && $persisted->amount > 0) {
            $persisted->lot->update($lotAttributes);

            return $previousLotId;
        }

        if ($previousLotId !== null) {
            $sweepCandidates[] = $previousLotId;
        }

        return Lot::query()->create([...$lotAttributes, 'metadata' => []])->id;
    }

    /**
     * @param  list<int>  $lotIds
     */
    private function sweepOrphanedLots(array $lotIds): void
    {
        if ($lotIds === []) {
            return;
        }

        Lot::query()
            ->whereIn('id', array_unique($lotIds))
            ->whereDoesntHave('postings')
            ->delete();
    }
}
