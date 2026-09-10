<?php

namespace App\Console\Commands\Financial;

use App\Models\Financial\RecurringTransaction;
use App\Support\Financial\RecurringTransactionMaterializer;
use Illuminate\Console\Command;

class PostRecurringTransactions extends Command
{
    protected $signature = 'financial:post-recurring';

    protected $description = 'Post every scheduled transaction due within the lead window and advance each schedule past it.';

    public function handle(RecurringTransactionMaterializer $materializer): int
    {
        $posted = 0;

        RecurringTransaction::query()
            ->orderBy('next_due_on')
            ->orderBy('id')
            ->each(function (RecurringTransaction $schedule) use ($materializer, &$posted): void {
                $created = count($materializer->materialize($schedule));

                if ($created > 0) {
                    $this->line(($schedule->memo ?? "Schedule {$schedule->id}").": {$created} posted");
                }

                $posted += $created;
            });

        $this->info("{$posted} transaction(s) posted.");

        return self::SUCCESS;
    }
}
