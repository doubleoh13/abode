<?php

namespace App\Models\Financial;

use App\Casts\BigDecimalCast;
use App\Enums\Financial\PostingStatus;
use Database\Factories\Financial\RecurringPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['financial_recurring_transaction_id', 'position', 'status', 'financial_account_id', 'financial_commodity_id', 'amount', 'memo', 'metadata'])]
class RecurringPosting extends Model
{
    /** @use HasFactory<RecurringPostingFactory> */
    use HasFactory;

    protected $table = 'financial_recurring_postings';

    /**
     * @return BelongsTo<RecurringTransaction, $this>
     */
    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class, 'financial_recurring_transaction_id');
    }

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
            'position' => 'integer',
            'status' => PostingStatus::class,
            'amount' => BigDecimalCast::class,
            'metadata' => 'array',
        ];
    }
}
