<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreBalanceAssertionRequest;
use App\Http\Resources\Financial\BalanceAssertionResource;
use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use Dedoc\Scramble\Attributes\Group;
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

    public function store(StoreBalanceAssertionRequest $request): BalanceAssertionResource
    {
        $assertion = BalanceAssertion::query()->create($request->validated());

        return new BalanceAssertionResource($assertion->load('commodity'));
    }

    public function destroy(BalanceAssertion $balanceAssertion): Response
    {
        $balanceAssertion->delete();

        return response()->noContent();
    }
}
