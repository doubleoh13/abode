<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\ApiTokenResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Authentication')]
class LoginController extends Controller
{
    /**
     * Exchange email and password for an API token.
     *
     * The plain text token is returned once and cannot be retrieved again;
     * send it as a bearer token on every later request.
     */
    public function store(LoginRequest $request): ApiTokenResource
    {
        $token = $request->authenticatedUser()->createToken($request->validated('device_name'));

        return (new ApiTokenResource($token->accessToken))
            ->additional(['plain_text_token' => $token->plainTextToken]);
    }

    /**
     * Revoke the token this request authenticated with.
     */
    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, Response::HTTP_CONFLICT, 'This request was not authenticated with a token.');

        $token->delete();

        return response()->noContent();
    }
}
