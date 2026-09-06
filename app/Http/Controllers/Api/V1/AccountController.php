<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $accounts = Account::query()->with('institution')->orderBy('name')->get();

        $accountsById = $accounts->keyBy('id');

        foreach ($accounts as $account) {
            if ($account->parent_id !== null) {
                $account->setRelation('parent', $accountsById[$account->parent_id]);
            }
        }

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $account = Account::query()->create($request->validated());

        return new AccountResource($account->load('institution'));
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account->load('institution'));
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account->update($request->validated());

        return new AccountResource($account->load('institution'));
    }

    public function destroy(Account $account): Response
    {
        abort_if(
            $account->children()->exists(),
            Response::HTTP_CONFLICT,
            'Delete or reparent its child accounts first.',
        );

        $account->delete();

        return response()->noContent();
    }
}
