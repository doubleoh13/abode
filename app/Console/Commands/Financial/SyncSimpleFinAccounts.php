<?php

namespace App\Console\Commands\Financial;

use App\Models\Financial\Account;
use App\Support\Financial\SimpleFin\SimpleFinClient;
use App\Support\Financial\SimpleFin\SimpleFinImporter;
use Illuminate\Console\Command;
use Throwable;

class SyncSimpleFinAccounts extends Command
{
    protected $signature = 'financial:simplefin-sync';

    protected $description = 'Pull bank transactions for every account mapped to SimpleFIN into the staging table.';

    public function handle(SimpleFinClient $client, SimpleFinImporter $importer): int
    {
        if (! $client->isConfigured()) {
            $this->line('SimpleFIN is not configured; nothing to sync.');

            return self::SUCCESS;
        }

        $failed = 0;

        Account::query()
            ->whereNotNull('simplefin_account_id')
            ->orderBy('id')
            ->each(function (Account $account) use ($importer, &$failed): void {
                try {
                    $result = $importer->sync($account);
                    $this->line("{$account->path}: {$result['created']} new, {$result['updated']} refreshed");
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("{$account->path}: {$exception->getMessage()}");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
