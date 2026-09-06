<?php

namespace App\Models\Financial;

use App\Enums\Financial\PostingStatus;
use Database\Factories\Financial\PostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['financial_transaction_id', 'position', 'status', 'financial_account_id', 'financial_commodity_id', 'financial_lot_id', 'amount', 'memo', 'metadata'])]
class Posting extends Model
{
    /** @use HasFactory<PostingFactory> */
    use HasFactory;

    protected $table = 'financial_postings';

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'financial_transaction_id');
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
     * @return BelongsTo<Lot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'financial_lot_id');
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
            'amount' => 'integer',
            'metadata' => 'array',
        ];
    }
}
