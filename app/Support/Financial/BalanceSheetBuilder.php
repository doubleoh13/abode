<?php

namespace App\Support\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\CommodityKind;
use App\Models\Financial\Commodity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives the balance sheet on the fly: per-account holdings in asset and
 * liability accounts as of the end of a date, valued at the last known price
 * on or before it, with cost basis allocated from lots the same way the SPA
 * does. Nothing here is ever stored.
 */
class BalanceSheetBuilder
{
    public function __construct(private readonly CostBasisBalancer $costBasisBalancer) {}

    /**
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array{assets: string, liabilities: string, net_worth: string}}
     */
    public function build(CarbonImmutable $asOf): array
    {
        $holdings = $this->holdings($asOf);
        $commodities = Commodity::query()
            ->findMany($holdings->pluck('financial_commodity_id')->unique())
            ->keyBy('id');

        $pricedCommodityIds = $commodities
            ->filter(fn (Commodity $commodity): bool => $commodity->kind !== CommodityKind::Currency)
            ->keys();
        $prices = $this->pricesAsOf($pricedCommodityIds->all(), $asOf);
        $bases = $this->lotBases($asOf);

        $rows = [];
        $totals = [
            AccountType::Asset->value => BigDecimal::zero(),
            AccountType::Liability->value => BigDecimal::zero(),
        ];

        foreach ($holdings as $holding) {
            /** @var Commodity $commodity */
            $commodity = $commodities[$holding->financial_commodity_id];
            $price = $prices[$holding->financial_commodity_id] ?? null;
            $quantity = BigDecimal::of($holding->quantity);
            $marketValue = $this->marketValue($quantity, $commodity->kind, $price);

            if ($marketValue !== null) {
                $totals[$holding->account_type] = $totals[$holding->account_type]->plus($marketValue);
            }

            $rows[] = [
                'financial_account_id' => $holding->financial_account_id,
                'financial_commodity_id' => $holding->financial_commodity_id,
                'account_type' => $holding->account_type,
                'quantity' => (string) $quantity->strippedOfTrailingZeros(),
                'price' => $price !== null ? (string) $price->strippedOfTrailingZeros() : null,
                'market_value' => $marketValue !== null ? (string) $marketValue->strippedOfTrailingZeros() : null,
                'cost_basis' => $this->costBasis($holding, $quantity, $commodity->kind, $bases),
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'totals' => [
                'assets' => (string) $totals[AccountType::Asset->value]->strippedOfTrailingZeros(),
                'liabilities' => (string) $totals[AccountType::Liability->value]->strippedOfTrailingZeros(),
                'net_worth' => (string) $totals[AccountType::Asset->value]
                    ->plus($totals[AccountType::Liability->value])
                    ->strippedOfTrailingZeros(),
            ],
        ];
    }

    /**
     * Non-zero per-commodity posting sums for asset and liability accounts,
     * excluding accounts opened after the report date.
     *
     * @return Collection<int, object{financial_account_id: int, financial_commodity_id: int, account_type: string, quantity: string}>
     */
    private function holdings(CarbonImmutable $asOf): Collection
    {
        return DB::table('financial_postings')
            ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'financial_postings.financial_account_id')
            ->whereIn('financial_accounts.account_type', [AccountType::Asset->value, AccountType::Liability->value])
            ->where('financial_transactions.date', '<=', $asOf->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('financial_accounts.opened_at')
                ->orWhere('financial_accounts.opened_at', '<=', $asOf->toDateString()))
            ->groupBy('financial_postings.financial_account_id', 'financial_postings.financial_commodity_id', 'financial_accounts.account_type')
            ->havingRaw('sum(financial_postings.amount) <> 0')
            ->orderBy('financial_postings.financial_account_id')
            ->orderBy('financial_postings.financial_commodity_id')
            ->selectRaw('financial_postings.financial_account_id, financial_postings.financial_commodity_id, financial_accounts.account_type, sum(financial_postings.amount) as quantity')
            ->get();
    }

    /**
     * Last known price per commodity on or before the end of the report date
     * (UTC), keyed by commodity id.
     *
     * @param  list<int>  $commodityIds
     * @return array<int, BigDecimal>
     */
    private function pricesAsOf(array $commodityIds, CarbonImmutable $asOf): array
    {
        if ($commodityIds === []) {
            return [];
        }

        return DB::table('financial_commodity_prices')
            ->whereIn('financial_commodity_id', $commodityIds)
            ->where('priced_at', '<', $asOf->addDay()->toDateString())
            ->orderBy('financial_commodity_id')
            ->orderByDesc('priced_at')
            ->selectRaw('distinct on (financial_commodity_id) financial_commodity_id, price')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->financial_commodity_id => BigDecimal::of($row->price),
            ])
            ->all();
    }

    /**
     * Cost basis per "account id:commodity id" key: each lot's total cost
     * allocated pro rata to the quantity still open in that account, using
     * the same denominator as the SPA (the lot's all-time acquired
     * quantity).
     *
     * @return array<string, BigDecimal>
     */
    private function lotBases(CarbonImmutable $asOf): array
    {
        $acquiredQuantities = DB::table('financial_postings')
            ->whereNotNull('financial_lot_id')
            ->where('amount', '>', 0)
            ->groupBy('financial_lot_id')
            ->selectRaw('financial_lot_id, sum(amount) as acquired_quantity')
            ->pluck('acquired_quantity', 'financial_lot_id');

        $openLots = DB::table('financial_postings')
            ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'financial_postings.financial_account_id')
            ->join('financial_lots', 'financial_lots.id', '=', 'financial_postings.financial_lot_id')
            ->whereIn('financial_accounts.account_type', [AccountType::Asset->value, AccountType::Liability->value])
            ->where('financial_transactions.date', '<=', $asOf->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('financial_accounts.opened_at')
                ->orWhere('financial_accounts.opened_at', '<=', $asOf->toDateString()))
            ->groupBy('financial_postings.financial_account_id', 'financial_postings.financial_commodity_id', 'financial_lots.id')
            ->selectRaw('financial_postings.financial_account_id, financial_postings.financial_commodity_id, financial_lots.id as lot_id, financial_lots.cost, sum(financial_postings.amount) as open_quantity')
            ->get();

        $bases = [];

        foreach ($openLots as $lot) {
            $key = "{$lot->financial_account_id}:{$lot->financial_commodity_id}";
            $bases[$key] ??= BigDecimal::zero();

            $openQuantity = BigDecimal::of($lot->open_quantity);
            $acquiredQuantity = BigDecimal::of($acquiredQuantities->get($lot->lot_id, 0));

            if ($openQuantity->isNegativeOrZero() || $acquiredQuantity->isNegativeOrZero()) {
                continue;
            }

            $share = $this->costBasisBalancer->allocate($lot->cost, $acquiredQuantity, [$openQuantity])[0];
            $bases[$key] = $bases[$key]->plus($share);
        }

        return $bases;
    }

    /**
     * @param  array<string, BigDecimal>  $bases
     */
    private function costBasis(object $holding, BigDecimal $quantity, CommodityKind $kind, array $bases): ?string
    {
        if ($kind === CommodityKind::Currency) {
            return (string) $quantity->strippedOfTrailingZeros();
        }

        $basis = $bases["{$holding->financial_account_id}:{$holding->financial_commodity_id}"] ?? null;

        return $basis !== null ? (string) $basis->strippedOfTrailingZeros() : null;
    }

    /**
     * Mirrors money.ts marketValue: currencies at face, priced commodities
     * at price x quantity — null when the exact product exceeds 25
     * fractional places or 53 integer digits, matching the SPA's
     * reject-over-round rule.
     */
    private function marketValue(BigDecimal $quantity, CommodityKind $kind, ?BigDecimal $price): ?BigDecimal
    {
        if ($kind === CommodityKind::Currency) {
            return $quantity;
        }

        if ($price === null) {
            return null;
        }

        $value = $price->multipliedBy($quantity)->strippedOfTrailingZeros();

        if ($value->getScale() > 25 || $value->abs()->compareTo(BigDecimal::of(10)->power(53)) >= 0) {
            return null;
        }

        return $value;
    }
}
