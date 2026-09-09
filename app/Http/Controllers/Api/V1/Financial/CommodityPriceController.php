<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreCommodityPriceRequest;
use App\Http\Resources\Financial\CommodityPriceResource;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Financial / Commodities')]
class CommodityPriceController extends Controller
{
    /**
     * A commodity's price points, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'financial_commodity_id' => ['required', 'integer', Rule::exists(Commodity::class, 'id')],
        ]);

        return CommodityPriceResource::collection(
            CommodityPrice::query()
                ->where('financial_commodity_id', $validated['financial_commodity_id'])
                ->orderByDesc('priced_at')
                ->paginate(50)
                ->withQueryString(),
        );
    }

    /**
     * Record an immutable base-currency price point for a commodity.
     */
    public function store(StoreCommodityPriceRequest $request): CommodityPriceResource
    {
        return new CommodityPriceResource(CommodityPrice::query()->create($request->validated()));
    }

    public function destroy(CommodityPrice $commodityPrice): Response
    {
        $commodityPrice->delete();

        return response()->noContent();
    }
}
