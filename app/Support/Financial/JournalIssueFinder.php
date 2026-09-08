<?php

namespace App\Support\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Derives the journal's soft integrity issues on the fly: nothing here is
 * ever stored, and nothing here ever blocks a save. The whole journal is
 * scanned on every call, so rows are read flat instead of hydrating models.
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
        $currentLotId = null;
        $runningQuantity = BigDecimal::zero();
        $flagged = false;

        $postings = DB::table('financial_postings')
            ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
            ->whereNotNull('financial_postings.financial_lot_id')
            ->orderBy('financial_postings.financial_lot_id')
            ->orderBy('financial_transactions.date')
            ->orderBy('financial_postings.financial_transaction_id')
            ->orderBy('financial_postings.position')
            ->get([
                'financial_postings.id',
                'financial_postings.financial_lot_id',
                'financial_postings.financial_transaction_id',
                'financial_postings.amount',
            ]);

        foreach ($postings as $posting) {
            if ($posting->financial_lot_id !== $currentLotId) {
                $currentLotId = $posting->financial_lot_id;
                $runningQuantity = BigDecimal::zero();
                $flagged = false;
            }

            if ($flagged) {
                continue;
            }

            $runningQuantity = $runningQuantity->plus($posting->amount);

            if ($runningQuantity->isNegative()) {
                $flagged = true;
                $issues[] = [
                    'type' => 'negative_lot',
                    'financial_lot_id' => $posting->financial_lot_id,
                    'financial_transaction_id' => $posting->financial_transaction_id,
                    'financial_posting_id' => $posting->id,
                    'message' => 'This posting drives its lot to a negative quantity.',
                ];
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
            ->pluck('acquired_quantity', 'financial_lot_id');

        $postings = DB::table('financial_postings')
            ->leftJoin('financial_lots', 'financial_lots.id', '=', 'financial_postings.financial_lot_id')
            ->orderBy('financial_postings.financial_transaction_id')
            ->orderBy('financial_postings.position')
            ->get([
                'financial_postings.financial_transaction_id',
                'financial_postings.financial_commodity_id',
                'financial_postings.financial_lot_id',
                'financial_postings.amount',
                'financial_lots.cost as lot_cost',
            ]);

        $legsByTransaction = [];

        foreach ($postings as $posting) {
            $legsByTransaction[$posting->financial_transaction_id][] = [
                'is_base' => (int) $posting->financial_commodity_id === $baseCurrencyId,
                'amount' => $posting->amount,
                'lot_key' => $posting->financial_lot_id,
                'lot_cost' => $posting->lot_cost,
                'lot_total_quantity' => $posting->financial_lot_id !== null
                    ? $acquiredQuantities->get($posting->financial_lot_id, 0)
                    : null,
            ];
        }

        foreach ($legsByTransaction as $transactionId => $legs) {
            $residual = $this->costBasisBalancer->residual($legs);

            if (! $residual->isZero()) {
                $issues[] = [
                    'type' => 'unbalanced_transaction',
                    'financial_transaction_id' => $transactionId,
                    'residual' => (string) $residual,
                    'message' => 'Postings no longer balance at cost.',
                ];
            }
        }

        return $issues;
    }
}
