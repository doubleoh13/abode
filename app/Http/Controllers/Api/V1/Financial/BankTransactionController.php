<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Enums\Financial\PostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\MatchBankTransactionRequest;
use App\Http\Resources\Financial\BankTransactionResource;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Posting;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

#[Group('Financial / Bank Transactions')]
class BankTransactionController extends Controller
{
    private const array CANDIDATE_LOADS = ['account', 'commodity', 'transaction.payee'];

    /**
     * Bank-reported transactions, newest first. `state` is `unresolved`
     * (default: neither matched nor ignored), `ignored`, or `matched`.
     * Unresolved rows carry candidate postings to match against.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'state' => ['nullable', 'in:unresolved,ignored,matched'],
            'financial_account_id' => ['nullable', 'integer'],
        ]);

        $state = $validated['state'] ?? 'unresolved';

        $page = BankTransaction::query()
            ->with('account')
            ->when($state === 'unresolved', fn (Builder $query) => $query->unresolved())
            ->when($state === 'ignored', fn (Builder $query) => $query->whereNotNull('ignored_at'))
            ->when($state === 'matched', fn (Builder $query) => $query->whereNotNull('financial_posting_id'))
            ->when($validated['financial_account_id'] ?? null, fn (Builder $query, int $accountId) => $query->where('financial_account_id', $accountId))
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->paginate(50);

        if ($state === 'unresolved') {
            $this->attachCandidates($page->getCollection());
        }

        return BankTransactionResource::collection($page);
    }

    /**
     * Link a posting to the bank transaction. A pending posting becomes
     * cleared when the bank reports the transaction as posted.
     */
    public function match(MatchBankTransactionRequest $request, BankTransaction $bankTransaction): BankTransactionResource
    {
        DB::transaction(function () use ($request, $bankTransaction): void {
            $posting = Posting::query()->findOrFail((int) $request->validated('financial_posting_id'));

            $bankTransaction->update(['financial_posting_id' => $posting->id, 'ignored_at' => null]);

            if (! $bankTransaction->pending && $posting->status === PostingStatus::Pending) {
                $posting->update(['status' => PostingStatus::Cleared]);
            }
        });

        return new BankTransactionResource($bankTransaction->load('account'));
    }

    /**
     * Drop the bank transaction from the inbox for good. The link, if any,
     * is released.
     */
    public function ignore(BankTransaction $bankTransaction): BankTransactionResource
    {
        $bankTransaction->update(['ignored_at' => CarbonImmutable::now(), 'financial_posting_id' => null]);

        return new BankTransactionResource($bankTransaction->load('account'));
    }

    /**
     * Return an ignored bank transaction to the inbox.
     */
    public function unignore(BankTransaction $bankTransaction): BankTransactionResource
    {
        $bankTransaction->update(['ignored_at' => null]);

        return new BankTransactionResource($bankTransaction->load('account'));
    }

    /**
     * @param  Collection<int, BankTransaction>  $rows
     */
    private function attachCandidates(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $window = (int) config('financial.simplefin.match_window_days');
        $earliest = $rows->min('posted_on')->subDays($window)->toDateString();
        $latest = $rows->max('posted_on')->addDays($window)->toDateString();

        $postings = Posting::query()
            ->with(self::CANDIDATE_LOADS)
            ->whereIn('financial_account_id', $rows->pluck('financial_account_id')->unique())
            ->whereDoesntHave('bankTransaction')
            ->whereHas('transaction', fn (Builder $transaction) => $transaction->whereBetween('date', [$earliest, $latest]))
            ->get();

        foreach ($rows as $row) {
            $from = $row->posted_on->subDays($window);
            $to = $row->posted_on->addDays($window);

            $row->candidates = $postings
                ->filter(fn (Posting $posting): bool => $posting->financial_account_id === $row->financial_account_id
                    && $posting->amount->isEqualTo($row->amount)
                    && $posting->transaction->date->betweenIncluded($from, $to))
                ->sortBy(fn (Posting $posting): int => abs($posting->transaction->date->diffInDays($row->posted_on)))
                ->values();
        }
    }
}
