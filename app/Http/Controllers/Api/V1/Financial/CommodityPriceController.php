<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreCommodityPriceRequest;
use App\Models\Financial\CommodityPrice;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Financial / Commodities')]
class CommodityPriceController extends Controller
{
    /**
     * Record an immutable base-currency price point for a commodity.
     */
    public function store(StoreCommodityPriceRequest $request): JsonResponse
    {
        $price = CommodityPrice::query()->create($request->validated());

        return response()->json(['data' => [
            'id' => $price->id,
            'financial_commodity_id' => $price->financial_commodity_id,
            'priced_at' => $price->priced_at->toIso8601String(),
            'price' => (string) $price->price,
        ]], 201);
    }
}
