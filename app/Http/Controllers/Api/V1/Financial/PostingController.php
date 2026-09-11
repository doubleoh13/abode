<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Enums\Financial\PostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\UpdatePostingRequest;
use App\Http\Resources\Financial\PostingResource;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Posting;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Financial / Transactions')]
class PostingController extends Controller
{
    /**
     * A register scoped to an account, a commodity, or both: matching
     * postings newest first, each carrying the running balance of its
     * commodity within that scope as of that posting.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_account_id' => ['nullable', 'required_without:financial_commodity_id', 'integer', Rule::exists(Account::class, 'id')],
            'financial_commodity_id' => ['nullable', 'required_without:financial_account_id', 'integer', Rule::exists(Commodity::class, 'id')],
            /**
             * Leave reconciled postings out of the page. Running balances still count them.
             */
            'hide_reconciled' => ['sometimes', 'boolean'],
            /**
             * Widen an account register to every account beneath it.
             */
            'include_descendants' => ['sometimes', 'boolean'],
        ]);

        $accountIds = $this->accountIds($request, $validated['financial_account_id'] ?? null);
        $eagerLoads = ['account', 'commodity', 'transaction.payee'];

        // Sibling postings feed the account register's counter-account
        // column; the commodity register never renders them.
        if ($validated['financial_account_id'] ?? null) {
            $eagerLoads[] = 'transaction.postings.account';
            $eagerLoads[] = 'bankTransaction';
        }

        // Running balances are computed over every posting in scope first, so
        // hiding reconciled lines never changes the balance shown on the rest.
        $withRunningBalance = Posting::query()
            ->when($accountIds, fn (Builder $query, array $ids) => $query
                ->whereIn('financial_postings.financial_account_id', $ids))
            ->when($validated['financial_commodity_id'] ?? null, fn (Builder $query, int $commodityId) => $query
                ->where('financial_postings.financial_commodity_id', $commodityId))
            ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
            ->select('financial_postings.*')
            ->selectRaw('financial_transactions.date as transaction_date')
            ->selectRaw(<<<'SQL'
                sum(financial_postings.amount) over (
                    partition by financial_postings.financial_commodity_id
                    order by financial_transactions.date, financial_postings.financial_transaction_id, financial_postings.position
                ) as running_balance
                SQL)
            ->toBase();

        return PostingResource::collection(
            Posting::query()
                ->fromSub($withRunningBalance, 'financial_postings')
                ->when($request->boolean('hide_reconciled'), fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query
                        ->whereNull('status')
                        ->orWhere('status', '!=', PostingStatus::Reconciled)))
                ->orderByDesc('transaction_date')
                ->orderByDesc('financial_transaction_id')
                ->orderByDesc('position')
                ->with($eagerLoads)
                ->paginate(50)
                ->withQueryString(),
        );
    }

    /**
     * @return array<int, int>|null
     */
    private function accountIds(Request $request, ?int $accountId): ?array
    {
        if ($accountId === null) {
            return null;
        }

        return $request->boolean('include_descendants')
            ? Account::query()->findOrFail($accountId)->subtreeIds()
            : [$accountId];
    }

    /**
     * Update a posting's reconciliation status.
     */
    public function update(UpdatePostingRequest $request, Posting $posting): PostingResource
    {
        $posting->update($request->validated());

        return new PostingResource($posting->load(['account', 'commodity', 'lot']));
    }
}
