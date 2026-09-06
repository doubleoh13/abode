<?php

namespace App\Support\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;
use Brick\Math\BigInteger;

/**
 * Derives the journal's soft integrity issues on the fly: nothing here is
 * ever stored, and nothing here ever blocks a save.
 */
class JournalIssueFinder
{
    public function __construct(private readonly CostBasisBalancer $costBasisBalancer) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function find(): array
    {
        return [...$this->negativeLotIssues(), ...$this->unbalancedTransactionIssues()];
    }

    /**
     * Walk each lot's postings in (transaction date, transaction id,
     * position) order and flag the first posting driving the running
     * quantity below zero.
     *
     * @return list<array<string, mixed>>
     */
    private function negativeLotIssues(): array
    {
        $issues = [];

        foreach (Lot::query()->with('postings.transaction')->get() as $lot) {
            $orderedPostings = $lot->postings->sortBy([
                fn (Posting $a, Posting $b): int => $a->transaction->date <=> $b->transaction->date,
                fn (Posting $a, Posting $b): int => $a->financial_transaction_id <=> $b->financial_transaction_id,
                fn (Posting $a, Posting $b): int => $a->position <=> $b->position,
            ]);

            $runningQuantity = BigInteger::zero();

            foreach ($orderedPostings as $posting) {
                $runningQuantity = $runningQuantity->plus($posting->amount);

                if ($runningQuantity->isNegative()) {
                    $issues[] = [
                        'type' => 'negative_lot',
                        'financial_lot_id' => $lot->id,
                        'financial_transaction_id' => $posting->financial_transaction_id,
                        'financial_posting_id' => $posting->id,
                        'message' => 'This posting drives its lot to a negative quantity.',
                    ];

                    break;
                }
            }
        }

        return $issues;
    }

    /**
     * Recompute balance-at-cost for every transaction against persisted lot
     * data; lot edits can retroactively unbalance an old save.
     *
     * @return list<array<string, mixed>>
     */
    private function unbalancedTransactionIssues(): array
    {
        $issues = [];
        $baseCurrencyId = Commodity::baseCurrency()->id;

        $acquiredQuantities = Posting::query()
            ->whereNotNull('financial_lot_id')
            ->where('amount', '>', 0)
            ->groupBy('financial_lot_id')
            ->selectRaw('financial_lot_id, sum(amount) as acquired_quantity')
            ->pluck('acquired_quantity', 'financial_lot_id')
            ->map(fn (string|int $quantity): BigInteger => BigInteger::of($quantity));

        foreach (Transaction::query()->with('postings.lot')->get() as $transaction) {
            $legs = $transaction->postings->map(fn (Posting $posting): array => [
                'is_base' => $posting->financial_commodity_id === $baseCurrencyId,
                'amount' => $posting->amount,
                'lot_key' => $posting->financial_lot_id,
                'lot_cost' => $posting->lot?->cost,
                'lot_total_quantity' => $posting->financial_lot_id !== null
                    ? $acquiredQuantities->get($posting->financial_lot_id, 0)
                    : null,
            ])->values()->all();

            $residual = $this->costBasisBalancer->residual($legs);

            if (! $residual->isZero()) {
                $issues[] = [
                    'type' => 'unbalanced_transaction',
                    'financial_transaction_id' => $transaction->id,
                    'residual' => (string) $residual,
                    'message' => 'Postings no longer balance at cost.',
                ];
            }
        }

        return $issues;
    }
}
