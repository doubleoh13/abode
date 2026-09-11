<?php

namespace App\Support\Financial;

use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pairs unmatched bank rows with the account's postings they plainly
 * settle: same amount, dates within the match window, neither side linked,
 * and the pairing unambiguous in both directions. Anything less is left
 * for a person to decide.
 */
class BankTransactionMatcher
{
    /**
     * @param  Collection<int, BankTransaction>  $rows
     * @return array<int, int> bank transaction id => posting id
     */
    public function proposals(Account $account, Collection $rows): array
    {
        $unmatched = $rows->whereNull('financial_posting_id');

        if ($unmatched->isEmpty()) {
            return [];
        }

        $window = (int) config('financial.simplefin.match_window_days');
        $earliest = CarbonImmutable::parse($unmatched->min('posted_on'))->subDays($window);
        $latest = CarbonImmutable::parse($unmatched->max('posted_on'))->addDays($window);

        $postings = Posting::query()
            ->where('financial_account_id', $account->id)
            ->where('financial_commodity_id', Commodity::baseCurrency()->id)
            ->whereDoesntHave('bankTransaction')
            ->whereHas('transaction', fn ($query) => $query->whereBetween('date', [$earliest->toDateString(), $latest->toDateString()]))
            ->with('transaction')
            ->get();

        /** @var array<int, list<int>> $candidatesByRow */
        $candidatesByRow = [];
        /** @var array<int, list<int>> $rowsByPosting */
        $rowsByPosting = [];

        foreach ($unmatched as $row) {
            foreach ($postings as $posting) {
                if ($this->plainlyMatches($row, $posting, $window)) {
                    $candidatesByRow[$row->id][] = $posting->id;
                    $rowsByPosting[$posting->id][] = $row->id;
                }
            }
        }

        $proposals = [];

        foreach ($candidatesByRow as $rowId => $postingIds) {
            if (count($postingIds) === 1 && count($rowsByPosting[$postingIds[0]]) === 1) {
                $proposals[$rowId] = $postingIds[0];
            }
        }

        return $proposals;
    }

    private function plainlyMatches(BankTransaction $row, Posting $posting, int $window): bool
    {
        if ($row->hasRejected($posting) || ! $posting->amount->isEqualTo($row->amount)) {
            return false;
        }

        return $row->posted_on->diffInDays($posting->transaction->date, true) <= $window;
    }
}
