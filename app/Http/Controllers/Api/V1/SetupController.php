<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Group('Setup')]
class SetupController extends Controller
{
    /**
     * Whether first-run setup is still required.
     *
     * @unauthenticated
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['required' => User::query()->doesntExist()]]);
    }

    /**
     * Create the first user and start their session.
     *
     * Only available while no user exists.
     *
     * @unauthenticated
     */
    public function store(SetupRequest $request): UserResource
    {
        $user = DB::transaction(function () use ($request): User {
            abort_if(User::query()->exists(), 404);

            $user = User::query()->create($request->validated());
            $user->permissions()->createMany([
                ['permission' => Permission::ViewFinances],
                ['permission' => Permission::ManageFinances],
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new UserResource($user->load('permissions'));
    }
}
