<?php

namespace App\Support\Financial;

use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\BankTransaction;
use App\Models\Financial\Posting;
use App\Models\Financial\RecurringPosting;
use Illuminate\Support\Facades\DB;

/**
 * Folds one account into another and deletes it. Everything that referenced
 * the source now references the target; the source's balance assertions are
 * dropped because the balance they asserted no longer exists on its own.
 */
class AccountMerger
{
    public function merge(Account $source, Account $target): Account
    {
        return DB::transaction(function () use ($source, $target): Account {
            Posting::query()->where('financial_account_id', $source->id)->update(['financial_account_id' => $target->id]);
            BankTransaction::query()->where('financial_account_id', $source->id)->update(['financial_account_id' => $target->id]);
            RecurringPosting::query()->where('financial_account_id', $source->id)->update(['financial_account_id' => $target->id]);
            BalanceAssertion::query()->where('financial_account_id', $source->id)->delete();
            Account::query()->where('parent_id', $source->id)->update(['parent_id' => $target->id]);

            $source->notes()->update(['noteable_id' => $target->id]);
            $source->attachments()->update(['attachable_id' => $target->id]);

            if ($target->simplefin_account_id === null && $source->simplefin_account_id !== null) {
                $mapping = $source->only(['simplefin_account_id', 'simplefin_synced_at', 'simplefin_balance', 'simplefin_balance_date']);

                $source->update(['simplefin_account_id' => null]);
                $target->fill($mapping);
            }

            if ($target->children()->exists() && Posting::query()->where('financial_account_id', $target->id)->exists()) {
                $target->allow_postings = true;
            }

            $target->save();
            $source->delete();

            return $target;
        });
    }
}
