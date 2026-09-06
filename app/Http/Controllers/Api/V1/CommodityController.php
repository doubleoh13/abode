<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommodityRequest;
use App\Http\Requests\UpdateCommodityRequest;
use App\Http\Resources\CommodityResource;
use App\Models\Commodity;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
