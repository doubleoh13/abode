<?php

namespace App\Http\Controllers\Api\V1\Financial;

use App\Http\Controllers\Controller;
use App\Http\Resources\Financial\AccountResource;
use App\Models\Financial\Account;
use App\Support\Financial\SimpleFin\SimpleFinAccount;
use App\Support\Financial\SimpleFin\SimpleFinClient;
use App\Support\Financial\SimpleFin\SimpleFinImporter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

#[Group('Financial / SimpleFIN')]
class SimpleFinController extends Controller
{
    private const int CACHE_SECONDS = 3600;

    /**
     * The accounts SimpleFIN exposes, for mapping onto Abode accounts. The
     * bridge answer is cached for an hour, since it refreshes from banks
     * far less often than a form opens. `configured` is false when no
     * access URL is set, in which case `data` is empty.
     */
    public function accounts(SimpleFinClient $client): JsonResponse
    {
        if (! $client->isConfigured()) {
            return response()->json(['data' => [], 'configured' => false, 'errors' => []]);
        }

        $result = Cache::remember('simplefin.accounts', self::CACHE_SECONDS, fn (): array => $client->accounts());

        return response()->json([
            'data' => array_map(fn (SimpleFinAccount $account): array => [
                'id' => $account->id,
                'organization' => $account->organization,
                'name' => $account->name,
                'currency' => $account->currency,
                'balance' => $account->balance,
                'balance_date' => $account->balanceDate->toDateString(),
            ], $result['accounts']),
            'configured' => true,
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Pull the account's bank transactions from SimpleFIN into the staging
     * table and record the bank balance. Nothing is written to the journal.
     */
    public function sync(Account $account, SimpleFinClient $client, SimpleFinImporter $importer): AccountResource
    {
        abort_if($account->simplefin_account_id === null, Response::HTTP_CONFLICT, 'The account is not mapped to a SimpleFIN account.');
        abort_unless($client->isConfigured(), Response::HTTP_CONFLICT, 'SimpleFIN is not configured.');

        $result = $importer->sync($account);

        return (new AccountResource($account->refresh()->load('institution')))->additional($result);
    }
}
