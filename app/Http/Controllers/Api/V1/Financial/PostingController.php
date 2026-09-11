<?php

namespace App\Http\Controllers\Api\V1\Financial;

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
        ]);

        $eagerLoads = ['account', 'commodity', 'transaction.payee'];

        // Sibling postings feed the account register's counter-account
        // column; the commodity register never renders them.
        if ($validated['financial_account_id'] ?? null) {
            $eagerLoads[] = 'transaction.postings.account';
            $eagerLoads[] = 'bankTransaction';
        }

        return PostingResource::collection(
            Posting::query()
                ->when($validated['financial_account_id'] ?? null, fn (Builder $query, int $accountId) => $query
                    ->where('financial_account_id', $accountId))
                ->when($validated['financial_commodity_id'] ?? null, fn (Builder $query, int $commodityId) => $query
                    ->where('financial_postings.financial_commodity_id', $commodityId))
                ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
                ->select('financial_postings.*')
                ->selectRaw(<<<'SQL'
                    sum(financial_postings.amount) over (
                        partition by financial_postings.financial_commodity_id
                        order by financial_transactions.date, financial_postings.financial_transaction_id, financial_postings.position
                    ) as running_balance
                    SQL)
                ->orderByDesc('financial_transactions.date')
                ->orderByDesc('financial_postings.financial_transaction_id')
                ->orderByDesc('financial_postings.position')
                ->with($eagerLoads)
                ->paginate(50)
                ->withQueryString(),
        );
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
