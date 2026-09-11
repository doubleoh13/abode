<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Enums\Financial\PostingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreBalanceAssertionRequest;
use App\Http\Resources\Financial\BalanceAssertionResource;
use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\Posting;
use Brick\Math\BigDecimal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Financial / Journal')]
class BalanceAssertionController extends Controller
{
    /**
     * An account's reconciliation points, newest first. Each asserts the
     * balance of one commodity at the end of its date, inclusive.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
        ]);

        return BalanceAssertionResource::collection(
            BalanceAssertion::query()
                ->where('financial_account_id', $validated['financial_account_id'])
                ->with('commodity')
                ->orderByDesc('asserted_at')
                ->orderBy('financial_commodity_id')
                ->get(),
        );
    }

    /**
     * `holds` reports whether the journal agreed with the balance at save
     * time; `reconciled_postings` counts the postings marked reconciled in
     * the account and every account beneath it, which happens only when
     * `reconcile_postings` is set and the balance holds.
     */
    public function store(StoreBalanceAssertionRequest $request): BalanceAssertionResource
    {
        $assertion = BalanceAssertion::query()->create($request->safe()->except('reconcile_postings'));
        $holds = $this->holds($assertion);
        $reconciled = 0;

        if ($holds && $request->boolean('reconcile_postings')) {
            $reconciled = $this->accountPostingsThrough($assertion)
                ->whereNotNull('status')
                ->where('status', '!=', PostingStatus::Reconciled)
                ->update(['status' => PostingStatus::Reconciled]);
        }

        return (new BalanceAssertionResource($assertion->load('commodity')))
            ->additional(['holds' => $holds, 'reconciled_postings' => $reconciled]);
    }

    private function holds(BalanceAssertion $assertion): bool
    {
        $actual = BigDecimal::of($this->accountPostingsThrough($assertion)->sum('financial_postings.amount') ?: '0');

        return $actual->compareTo($assertion->balance) === 0;
    }

    /**
     * Postings in the asserted commodity dated on or before the assertion,
     * across the asserted account and every account beneath it.
     *
     * @return Builder<Posting>
     */
    private function accountPostingsThrough(BalanceAssertion $assertion): Builder
    {
        return Posting::query()
            ->whereIn('financial_postings.financial_account_id', $assertion->account->subtreeIds())
            ->where('financial_postings.financial_commodity_id', $assertion->financial_commodity_id)
            ->whereHas('transaction', fn (Builder $query) => $query->where('date', '<=', $assertion->asserted_at->toDateString()));
    }

    public function destroy(BalanceAssertion $balanceAssertion): Response
    {
        $balanceAssertion->delete();

        return response()->noContent();
    }
}
