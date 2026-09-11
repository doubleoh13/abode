<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\StoreInstitutionRequest;
use App\Http\Requests\Financial\UpdateInstitutionRequest;
use App\Http\Resources\Financial\InstitutionResource;
use App\Models\Financial\Institution;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Financial / Institutions')]
class InstitutionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return InstitutionResource::collection(
            Institution::query()->orderBy('name')->get(),
        );
    }

    public function store(StoreInstitutionRequest $request): InstitutionResource
    {
        return new InstitutionResource(Institution::query()->create($request->validated()));
    }

    public function show(Institution $institution): InstitutionResource
    {
        return new InstitutionResource($institution);
    }

    public function update(UpdateInstitutionRequest $request, Institution $institution): InstitutionResource
    {
        $institution->update($request->validated());

        return new InstitutionResource($institution);
    }

    public function destroy(Institution $institution): Response
    {
        abort_if(
            $institution->accounts()->exists(),
            Response::HTTP_CONFLICT,
            'Detach or delete its accounts first.',
        );

        $institution->delete();

        return response()->noContent();
    }
}
