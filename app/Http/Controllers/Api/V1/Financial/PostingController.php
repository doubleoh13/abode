<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\UpdatePostingRequest;
use App\Http\Resources\Financial\PostingResource;
use App\Models\Financial\Account;
use App\Models\Financial\Posting;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Financial / Transactions')]
class PostingController extends Controller
{
    /**
     * An account's register: its own postings newest first, each carrying
     * the running balance of its commodity as of that posting.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
        ]);

        return PostingResource::collection(
            Posting::query()
                ->where('financial_account_id', $validated['financial_account_id'])
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
                ->with(['commodity', 'transaction.payee', 'transaction.postings.account'])
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
