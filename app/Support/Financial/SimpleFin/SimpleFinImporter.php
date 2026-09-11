<?php

namespace App\Support\Financial\SimpleFin;

use App\Models\Financial\Account;
use App\Models\Financial\BankTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pulls bank-reported transactions for every mapped account into
 * financial_bank_transactions, upserting by SimpleFIN id so a pending row
 * turns into a posted one in place. Never touches the journal.
 */
class SimpleFinImporter
{
    public function __construct(private readonly SimpleFinClient $client) {}

    /**
     * @return array{created: int, updated: int, skipped: list<string>, errors: list<string>}
     */
    public function import(?CarbonImmutable $now = null, ?int $days = null): array
    {
        $now ??= CarbonImmutable::now();
        $mapped = Account::query()->whereNotNull('simplefin_account_id')->get()->keyBy('simplefin_account_id');

        if ($mapped->isEmpty()) {
            return ['created' => 0, 'updated' => 0, 'skipped' => [], 'errors' => []];
        }

        $result = $this->client->transactions($this->startDate($mapped, $now, $days));
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($result['accounts'] as $remote) {
            $account = $mapped->get($remote->id);

            if ($account === null) {
                $skipped[] = "{$remote->organization} · {$remote->name}";

                continue;
            }

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
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $result['errors']];
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
     * Earliest date any mapped account needs: an explicit day count, else
     * the overlap window before each account's newest row, or the initial
     * window for accounts never synced.
     *
     * @param  Collection<string, Account>  $mapped
     */
    private function startDate(Collection $mapped, CarbonImmutable $now, ?int $days): CarbonImmutable
    {
        $today = $now->setTimezone($this->timezone())->startOfDay();

        if ($days !== null) {
            return $today->subDays($days);
        }

        $newestByAccount = BankTransaction::query()
            ->whereIn('financial_account_id', $mapped->pluck('id'))
            ->groupBy('financial_account_id')
            ->pluck(DB::raw('MAX(posted_on) as newest'), 'financial_account_id');

        return $mapped
            ->map(function (Account $account) use ($newestByAccount, $today): CarbonImmutable {
                $newest = $newestByAccount->get($account->id);

                return $newest === null
                    ? $today->subDays((int) config('financial.simplefin.initial_days'))
                    : CarbonImmutable::parse($newest, $this->timezone())->subDays((int) config('financial.simplefin.overlap_days'));
            })
            ->min();
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
