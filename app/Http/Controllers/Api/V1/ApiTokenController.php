<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiTokenRequest;
use App\Http\Resources\ApiTokenResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('API Tokens')]
class ApiTokenController extends Controller
{
    /**
     * List the current user's API tokens.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ApiTokenResource::collection(
            $request->user()->tokens()->latest('id')->get()
        );
    }

    /**
     * Create an API token.
     *
     * The plain text token is returned once and cannot be retrieved again.
     */
    public function store(StoreApiTokenRequest $request): ApiTokenResource
    {
        $token = $request->user()->createToken($request->validated('name'));

        return (new ApiTokenResource($token->accessToken))
            ->additional(['plain_text_token' => $token->plainTextToken]);
    }

    /**
     * Revoke an API token.
     */
    public function destroy(Request $request, int $token): Response
    {
        $request->user()->tokens()->findOrFail($token)->delete();

        return response()->noContent();
    }
}
