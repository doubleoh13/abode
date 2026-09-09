<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreCommodityRequest;
use App\Http\Requests\Financial\UpdateCommodityRequest;
use App\Http\Resources\Financial\CommodityResource;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use Brick\Math\BigDecimal;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Financial / Commodities')]
class CommodityController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CommodityResource::collection(
            Commodity::query()->withLatestPrice()->orderBy('code')->get(),
        );
    }

    public function store(StoreCommodityRequest $request): CommodityResource
    {
        return new CommodityResource(Commodity::query()->create($request->validated()));
    }

    public function show(Commodity $commodity): CommodityResource
    {
        return new CommodityResource(
            Commodity::query()->withLatestPrice()->findOrFail($commodity->getKey()),
        );
    }

    /**
     * The commodity's per-account posting sums.
     */
    public function balances(Commodity $commodity): JsonResponse
    {
        $balances = Posting::query()
            ->where('financial_commodity_id', $commodity->id)
            ->groupBy('financial_account_id')
            ->selectRaw('financial_account_id, sum(amount) as balance')
            ->orderBy('financial_account_id')
            ->get()
            ->map(fn (Posting $row): array => [
                'financial_account_id' => $row->financial_account_id,
                'balance' => (string) BigDecimal::of($row->balance)->strippedOfTrailingZeros(),
            ]);

        return response()->json(['data' => $balances]);
    }

    public function update(UpdateCommodityRequest $request, Commodity $commodity): CommodityResource
    {
        $commodity->update($request->validated());

        return new CommodityResource($commodity);
    }

    public function destroy(Commodity $commodity): Response
    {
        abort_if(
            Posting::query()->where('financial_commodity_id', $commodity->id)->exists()
                || Lot::query()->where('financial_commodity_id', $commodity->id)->exists(),
            Response::HTTP_CONFLICT,
            'Journal postings reference this commodity.',
        );

        $commodity->delete();

        return response()->noContent();
    }
}
