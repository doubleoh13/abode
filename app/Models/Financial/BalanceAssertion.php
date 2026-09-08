<?php

namespace App\Models\Financial;

use App\Casts\BigDecimalCast;
use Database\Factories\Financial\BalanceAssertionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reconciliation point: the account's balance in one commodity at the END
 * of the asserted date, inclusive of that day's postings.
 */
#[Fillable(['financial_account_id', 'financial_commodity_id', 'asserted_at', 'balance', 'memo'])]
class BalanceAssertion extends Model
{
    /** @use HasFactory<BalanceAssertionFactory> */
    use HasFactory;

    protected $table = 'financial_balance_assertions';

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'financial_account_id');
    }

    /**
     * @return BelongsTo<Commodity, $this>
     */
    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class, 'financial_commodity_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asserted_at' => 'date',
            'balance' => BigDecimalCast::class,
        ];
    }
}
