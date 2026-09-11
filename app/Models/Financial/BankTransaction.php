<?php

namespace App\Models\Financial;

use App\Casts\BigDecimalCast;
use Database\Factories\Financial\BankTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transaction as a bank reported it. Evidence, not a journal entry: it
 * becomes part of the ledger only when linked to a posting.
 */
#[Fillable(['financial_account_id', 'source', 'external_id', 'posted_on', 'transacted_on', 'pending', 'amount', 'currency', 'description', 'payee', 'memo', 'payload', 'financial_posting_id', 'rejected_posting_ids'])]
class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory;

    public const string SOURCE_SIMPLEFIN = 'simplefin';

    protected $table = 'financial_bank_transactions';

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'financial_account_id');
    }

    /**
     * @return BelongsTo<Posting, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class, 'financial_posting_id');
    }

    /**
     * Whether the posting was declined as this row's match.
     */
    public function hasRejected(Posting $posting): bool
    {
        return in_array($posting->id, $this->rejected_posting_ids ?? [], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_on' => 'immutable_date',
            'transacted_on' => 'immutable_date',
            'pending' => 'boolean',
            'amount' => BigDecimalCast::class,
            'payload' => 'array',
            'rejected_posting_ids' => 'array',
        ];
    }
}
