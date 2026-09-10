<?php

namespace App\Support\Financial;

use App\Models\Financial\RecurringPosting;
use App\Models\Financial\RecurringTransaction;
use App\Models\Financial\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns due occurrences of a schedule into real journal transactions and
 * advances the schedule's pointer past each one.
 */
class RecurringTransactionMaterializer
{
    public function __construct(private readonly TransactionWriter $transactionWriter) {}

    /**
     * @return list<CarbonImmutable>
     */
    public function dueOccurrences(RecurringTransaction $schedule, ?CarbonImmutable $today = null): array
    {
        return $schedule->occurrencesDueBy(($today ?? CarbonImmutable::today())->addDays($schedule->leadDays()));
    }

    /**
     * @return list<Transaction>
     */
    public function materialize(RecurringTransaction $schedule, ?CarbonImmutable $today = null): array
    {
        return DB::transaction(function () use ($schedule, $today): array {
            $postings = $schedule->postings()->get();
            $created = [];

            foreach ($this->dueOccurrences($schedule, $today) as $dueOn) {
                $created[] = $this->transactionWriter->store([
                    'date' => $dueOn->toDateString(),
                    'financial_payee_id' => $schedule->financial_payee_id,
                    'financial_recurring_transaction_id' => $schedule->id,
                    'memo' => $schedule->memo,
                    'metadata' => $schedule->metadata,
                    'postings' => $postings->map(fn (RecurringPosting $posting): array => [
                        'status' => $posting->status,
                        'financial_account_id' => $posting->financial_account_id,
                        'financial_commodity_id' => $posting->financial_commodity_id,
                        'amount' => (string) $posting->amount,
                        'memo' => $posting->memo,
                        'metadata' => $posting->metadata,
                    ])->all(),
                ]);

                $schedule->update(['next_due_on' => $schedule->occurrenceAfter($dueOn)]);
            }

            return $created;
        });
    }
}
