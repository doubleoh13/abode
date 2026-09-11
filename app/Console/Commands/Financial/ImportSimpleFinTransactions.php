<?php

namespace App\Console\Commands\Financial;

use App\Support\Financial\SimpleFin\SimpleFinClient;
use App\Support\Financial\SimpleFin\SimpleFinImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportSimpleFinTransactions extends Command
{
    protected $signature = 'financial:simplefin-import {--days= : Re-read this many days instead of the computed window}';

    protected $description = 'Pull bank transactions for every account mapped to SimpleFIN into the review inbox.';

    public function handle(SimpleFinClient $client, SimpleFinImporter $importer): int
    {
        if (! $client->isConfigured()) {
            $this->line('SimpleFIN is not configured; nothing to import.');

            return self::SUCCESS;
        }

        $days = $this->option('days');

        try {
            $result = $importer->import(days: $days === null ? null : (int) $days);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        foreach ($result['skipped'] as $skipped) {
            $this->line("Not mapped, skipped: {$skipped}");
        }

        $this->info("{$result['created']} new, {$result['updated']} refreshed.");

        return self::SUCCESS;
    }
}
