<?php

namespace App\Support\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\CommodityKind;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Income and expense activity within a date window. Amounts are reported
 * from the reader's point of view: income earned and expenses spent are
 * both positive, so a refund shows as a negative expense.
 */
class IncomeStatementBuilder
{
    /**
     * @return array{from: string, to: string, rows: list<array{financial_account_id: int, financial_commodity_id: int, account_type: string, amount: string}>, totals: array{income: string, expenses: string, net: string}}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $totals = [
            AccountType::Income->value => BigDecimal::zero(),
            AccountType::Expense->value => BigDecimal::zero(),
        ];

        $activity = DB::table('financial_postings')
            ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
            ->join('financial_accounts', 'financial_accounts.id', '=', 'financial_postings.financial_account_id')
            ->join('financial_commodities', 'financial_commodities.id', '=', 'financial_postings.financial_commodity_id')
            ->whereIn('financial_accounts.account_type', [AccountType::Income->value, AccountType::Expense->value])
            ->whereBetween('financial_transactions.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('financial_postings.financial_account_id', 'financial_postings.financial_commodity_id', 'financial_accounts.account_type', 'financial_commodities.kind')
            ->havingRaw('sum(financial_postings.amount) <> 0')
            ->orderBy('financial_postings.financial_account_id')
            ->orderBy('financial_postings.financial_commodity_id')
            ->selectRaw('financial_postings.financial_account_id, financial_postings.financial_commodity_id, financial_accounts.account_type, financial_commodities.kind, sum(financial_postings.amount) as amount')
            ->get();

        foreach ($activity as $line) {
            $amount = BigDecimal::of($line->amount);

            if ($line->account_type === AccountType::Income->value) {
                $amount = $amount->negated();
            }

            if ($line->kind === CommodityKind::Currency->value) {
                $totals[$line->account_type] = $totals[$line->account_type]->plus($amount);
            }

            $rows[] = [
                'financial_account_id' => $line->financial_account_id,
                'financial_commodity_id' => $line->financial_commodity_id,
                'account_type' => $line->account_type,
                'amount' => (string) $amount->strippedOfTrailingZeros(),
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'totals' => [
                'income' => (string) $totals[AccountType::Income->value]->strippedOfTrailingZeros(),
                'expenses' => (string) $totals[AccountType::Expense->value]->strippedOfTrailingZeros(),
                'net' => (string) $totals[AccountType::Income->value]
                    ->minus($totals[AccountType::Expense->value])
                    ->strippedOfTrailingZeros(),
            ],
        ];
    }
}
