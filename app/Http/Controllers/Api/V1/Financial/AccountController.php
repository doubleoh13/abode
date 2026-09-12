<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreAccountRequest;
use App\Http\Requests\Financial\UpdateAccountRequest;
use App\Http\Resources\Financial\AccountResource;
use App\Models\Financial\Account;
use App\Models\Financial\Posting;
use Brick\Math\BigDecimal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Financial / Accounts')]
class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $accounts = Account::query()->with('institution')->withCount('unmatchedBankTransactions')->get();

        $accountsById = $accounts->keyBy('id');

        foreach ($accounts as $account) {
            if ($account->parent_id !== null) {
                $account->setRelation('parent', $accountsById[$account->parent_id]);
            }
        }

        return AccountResource::collection(
            $accounts->sortBy(fn (Account $account): string => $account->path, SORT_NATURAL | SORT_FLAG_CASE)->values(),
        );
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $account = Account::query()->create($request->validated());

        return new AccountResource($account->load('institution'));
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account->load('institution'));
    }

    /**
     * Per-account, per-commodity posting sums for the account, optionally
     * from a date, as of the end of a date, and across every account
     * beneath it.
     */
    public function balances(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date'],
            'include_descendants' => ['sometimes', 'boolean'],
        ]);

        $accountIds = $request->boolean('include_descendants') ? $account->subtreeIds() : [$account->id];

        $balances = Posting::query()
            ->whereIn('financial_account_id', $accountIds)
            ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query
                ->whereHas('transaction', fn (Builder $transaction) => $transaction->where('date', '>=', $from)))
            ->when($validated['as_of'] ?? null, fn (Builder $query, string $asOf) => $query
                ->whereHas('transaction', fn (Builder $transaction) => $transaction->where('date', '<=', $asOf)))
            ->groupBy('financial_account_id', 'financial_commodity_id')
            ->selectRaw('financial_account_id, financial_commodity_id, sum(amount) as balance')
            ->orderBy('financial_account_id')
            ->orderBy('financial_commodity_id')
            ->get()
            ->map(fn (Posting $row): array => [
                'financial_account_id' => $row->financial_account_id,
                'financial_commodity_id' => $row->financial_commodity_id,
                'balance' => (string) BigDecimal::of($row->balance)->strippedOfTrailingZeros(),
            ]);

        return response()->json(['data' => $balances]);
    }

    /**
     * Per-commodity posting sums grouped by calendar year or month, oldest
     * first, for the account and optionally every account beneath it. Only
     * periods with postings are returned.
     */
    public function periodTotals(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'group' => ['required', Rule::in(['year', 'month'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'include_descendants' => ['sometimes', 'boolean'],
        ]);

        $accountIds = $request->boolean('include_descendants') ? $account->subtreeIds() : [$account->id];
        $format = $validated['group'] === 'year' ? 'YYYY' : 'YYYY-MM';

        $totals = Posting::query()
            ->join('financial_transactions', 'financial_transactions.id', '=', 'financial_postings.financial_transaction_id')
            ->whereIn('financial_postings.financial_account_id', $accountIds)
            ->when($validated['from'] ?? null, fn (Builder $query, string $from) => $query
                ->where('financial_transactions.date', '>=', $from))
            ->when($validated['to'] ?? null, fn (Builder $query, string $to) => $query
                ->where('financial_transactions.date', '<=', $to))
            ->groupByRaw("to_char(financial_transactions.date, '{$format}'), financial_postings.financial_commodity_id")
            ->selectRaw("to_char(financial_transactions.date, '{$format}') as period, financial_postings.financial_commodity_id, sum(financial_postings.amount) as total")
            ->orderBy('period')
            ->orderBy('financial_postings.financial_commodity_id')
            ->get()
            ->map(fn (Posting $row): array => [
                'period' => $row->period,
                'financial_commodity_id' => $row->financial_commodity_id,
                'total' => (string) BigDecimal::of($row->total)->strippedOfTrailingZeros(),
            ]);

        return response()->json(['data' => $totals]);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account->update($request->validated());

        return new AccountResource($account->load('institution'));
    }

    public function destroy(Account $account): Response
    {
        abort_if(
            $account->children()->exists(),
            Response::HTTP_CONFLICT,
            'Delete or reparent its child accounts first.',
        );

        abort_if(
            Posting::query()->where('financial_account_id', $account->id)->exists(),
            Response::HTTP_CONFLICT,
            'It has journal postings.',
        );

        $account->delete();

        return response()->noContent();
    }
}
