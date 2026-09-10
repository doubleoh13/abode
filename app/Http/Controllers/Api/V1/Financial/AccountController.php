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

#[Group('Financial / Accounts')]
class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $accounts = Account::query()->with('institution')->get();

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
     * The account's own per-commodity posting sums, optionally as of the end
     * of a date.
     */
    public function balances(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        $balances = Posting::query()
            ->where('financial_account_id', $account->id)
            ->when($validated['as_of'] ?? null, fn (Builder $query, string $asOf) => $query
                ->whereHas('transaction', fn (Builder $transaction) => $transaction->where('date', '<=', $asOf)))
            ->groupBy('financial_commodity_id')
            ->selectRaw('financial_commodity_id, sum(amount) as balance')
            ->orderBy('financial_commodity_id')
            ->get()
            ->map(fn (Posting $row): array => [
                'financial_commodity_id' => $row->financial_commodity_id,
                'balance' => (string) BigDecimal::of($row->balance)->strippedOfTrailingZeros(),
            ]);

        return response()->json(['data' => $balances]);
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
