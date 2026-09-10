<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[Group('Current User')]
class CurrentUserController extends Controller
{
    /**
     * Show the authenticated user.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('permissions'));
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return new UserResource($user->load('permissions'));
    }

    /**
     * Change the authenticated user's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $request->user()->update(['password' => $request->validated('password')]);

        return response()->noContent();
    }
}
