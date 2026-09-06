<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use App\Http\Requests\Financial\StoreCommodityRequest;
use App\Http\Requests\Financial\UpdateCommodityRequest;
use App\Http\Resources\Financial\CommodityResource;
use App\Models\Financial\Commodity;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        $commodity->update($request->validated());

        return new CommodityResource($commodity);
    }

    public function destroy(Commodity $commodity): Response
    {
        $commodity->delete();

        return response()->noContent();
    }
}
