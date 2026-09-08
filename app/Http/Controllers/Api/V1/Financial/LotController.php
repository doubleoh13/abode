<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Resources\Financial\LotResource;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use Brick\Math\BigDecimal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

#[Group('Financial / Lots')]
class LotController extends Controller
{
    /**
     * Lots with open quantity, scoped to an account, a commodity, or both —
     * for picking which lot a reduction draws from or listing holdings.
     * Open quantity is guidance as of the given date (per account when one
     * is given, global otherwise); acquired quantity is the global
     * allocation denominator.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_account_id' => ['nullable', 'required_without:financial_commodity_id', 'integer', Rule::exists(Account::class, 'id')],
            'financial_commodity_id' => ['nullable', 'required_without:financial_account_id', 'integer', Rule::exists(Commodity::class, 'id')],
            'as_of' => ['nullable', 'date'],
        ]);

        $accountId = $validated['financial_account_id'] ?? null;

        $lots = Lot::query()
            ->when($validated['financial_commodity_id'] ?? null, fn (Builder $query, int $commodityId) => $query
                ->where('financial_commodity_id', $commodityId))
            ->when($accountId, fn (Builder $query, int $account) => $query
                ->whereHas('postings', fn (Builder $postings) => $postings->where('financial_account_id', $account)))
            ->withSum([
                'postings as open_quantity' => fn (Builder $query) => $query
                    ->when($accountId, fn (Builder $inAccount, int $account) => $inAccount
                        ->where('financial_account_id', $account))
                    ->when(
                        $validated['as_of'] ?? null,
                        fn (Builder $withinDate, string $asOf) => $withinDate->whereHas(
                            'transaction',
                            fn (Builder $transaction) => $transaction->where('date', '<=', $asOf),
                        ),
                    ),
            ], 'amount')
            ->withSum([
                'postings as acquired_quantity' => fn (Builder $query) => $query->where('amount', '>', 0),
            ], 'amount')
            ->orderBy('acquired_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Lot $lot): bool => BigDecimal::of($lot->open_quantity ?? 0)->isPositive())
            ->values();

        return LotResource::collection($lots);
    }
}
