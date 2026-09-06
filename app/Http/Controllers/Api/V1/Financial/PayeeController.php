<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StorePayeeRequest;
use App\Http\Requests\Financial\UpdatePayeeRequest;
use App\Http\Resources\Financial\PayeeResource;
use App\Models\Financial\Payee;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Financial / Payees')]
class PayeeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PayeeResource::collection(Payee::query()->orderBy('name')->get());
    }

    public function store(StorePayeeRequest $request): PayeeResource
    {
        return new PayeeResource(Payee::query()->create($request->validated()));
    }

    public function show(Payee $payee): PayeeResource
    {
        return new PayeeResource($payee);
    }

    public function update(UpdatePayeeRequest $request, Payee $payee): PayeeResource
    {
        $payee->update($request->validated());

        return new PayeeResource($payee);
    }

    public function destroy(Payee $payee): Response
    {
        $payee->delete();

        return response()->noContent();
    }
}
