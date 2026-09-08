<?php

namespace App\Models\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNotes;
use Database\Factories\Financial\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read PostingStatus|null $status
 */
#[Fillable(['date', 'financial_payee_id', 'memo', 'metadata'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasAttachments, HasFactory, HasNotes;

    protected $table = 'financial_transactions';

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class, 'financial_payee_id');
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class, 'financial_transaction_id')->orderBy('position');
    }

    public function opensLotsConsumedElsewhere(): bool
    {
        return Posting::query()
            ->whereIn('financial_lot_id', $this->postings()->where('amount', '>', 0)->select('financial_lot_id'))
            ->where('financial_transaction_id', '!=', $this->id)
            ->exists();
    }

    /**
     * Least-advanced status among Asset/Liability postings — the only legs
     * that carry one. Null for transactions touching neither.
     */
    protected function status(): Attribute
    {
        return Attribute::make(
            get: function (): ?PostingStatus {
                return $this->postings
                    ->filter(
                        fn (Posting $posting): bool => $posting->status !== null
                            && in_array($posting->account->account_type, [AccountType::Asset, AccountType::Liability], true),
                    )
                    ->map(fn (Posting $posting): PostingStatus => $posting->status)
                    ->sortBy(fn (PostingStatus $status): int => $status->rank())
                    ->first();
            },
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'metadata' => 'array',
        ];
    }
}
