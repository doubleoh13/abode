<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreCommodityRequest;
use App\Http\Requests\Financial\UpdateCommodityRequest;
use App\Http\Resources\Financial\CommodityResource;
use App\Models\Financial\Commodity;
use App\Models\Financial\Lot;
use App\Models\Financial\Posting;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

#[Group('Financial / Commodities')]
class CommodityController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CommodityResource::collection(
            Commodity::query()->orderBy('code')->get(),
        );
    }

    public function store(StoreCommodityRequest $request): CommodityResource
    {
        return new CommodityResource(Commodity::query()->create($request->validated()));
    }

    public function show(Commodity $commodity): CommodityResource
    {
        return new CommodityResource($commodity);
    }

    public function update(UpdateCommodityRequest $request, Commodity $commodity): CommodityResource
    {
        DB::transaction(function () use ($request, $commodity): void {
            $previousPrecision = $commodity->precision;

            $commodity->update($request->validated());

            $scale = 10 ** ($commodity->precision - $previousPrecision);

            if ($scale > 1) {
                Posting::query()
                    ->where('financial_commodity_id', $commodity->id)
                    ->update(['amount' => DB::raw("amount * {$scale}")]);

                if ($commodity->code === Commodity::BASE_CURRENCY_CODE) {
                    Lot::query()->update(['cost' => DB::raw("cost * {$scale}")]);
                }
            }
        });

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
