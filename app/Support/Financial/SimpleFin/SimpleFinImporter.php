<?php

namespace App\Support\Financial\SimpleFin;

use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls one mapped account's bank-reported transactions into
 * financial_bank_transactions, upserting by SimpleFIN id so a pending row
 * turns into a posted one in place. Never touches the journal.
 */
class SimpleFinImporter
{
    public function __construct(private readonly SimpleFinClient $client) {}

    /**
     * @return array{created: int, updated: int}
     */
    public function sync(Account $account, ?CarbonImmutable $now = null): array
    {
        if ($account->simplefin_account_id === null) {
            throw new RuntimeException('The account is not mapped to a SimpleFIN account.');
        }

        $now ??= CarbonImmutable::now();
        $remote = $this->client->accountTransactions($account->simplefin_account_id, $this->startDate($account, $now));

        if ($remote === null) {
            throw new RuntimeException('SimpleFIN no longer lists the mapped account.');
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($remote, $account, $now, &$created, &$updated): void {
            foreach ($remote->transactions as $transaction) {
                $this->upsert($account, $transaction) ? $created++ : $updated++;
            }

            $account->update([
                'simplefin_synced_at' => $now,
                'simplefin_balance' => $remote->balance,
                'simplefin_balance_date' => $this->localDate($remote->balanceDate),
            ]);
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Whether the row was created rather than refreshed.
     */
    private function upsert(Account $account, SimpleFinTransaction $transaction): bool
    {
        $attributes = [
            'financial_account_id' => $account->id,
            'posted_on' => $this->localDate($transaction->postedAt),
            'transacted_on' => $transaction->transactedAt === null ? null : $this->localDate($transaction->transactedAt),
            'pending' => $transaction->pending,
            'amount' => $transaction->amount,
            'currency' => 'USD',
            'description' => $transaction->description,
            'payee' => $transaction->payee,
            'memo' => $transaction->memo,
            'payload' => $transaction->payload,
        ];

        $existing = BankTransaction::query()
            ->where('source', BankTransaction::SOURCE_SIMPLEFIN)
            ->where('external_id', $transaction->id)
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return false;
        }

        BankTransaction::query()->create([
            ...$attributes,
            'source' => BankTransaction::SOURCE_SIMPLEFIN,
            'external_id' => $transaction->id,
        ]);

        return true;
    }

    /**
     * The overlap window before the account's newest row, or the initial
     * window when the account has never been synced.
     */
    private function startDate(Account $account, CarbonImmutable $now): CarbonImmutable
    {
        $newest = BankTransaction::query()
            ->where('financial_account_id', $account->id)
            ->max('posted_on');

        if ($newest === null) {
            return $now->setTimezone($this->timezone())->startOfDay()->subDays((int) config('financial.simplefin.initial_days'));
        }

        return CarbonImmutable::parse($newest, $this->timezone())->subDays((int) config('financial.simplefin.overlap_days'));
    }

    private function localDate(CarbonImmutable $timestamp): string
    {
        return $timestamp->setTimezone($this->timezone())->toDateString();
    }

    private function timezone(): string
    {
        return (string) config('financial.simplefin.timezone');
    }
}
