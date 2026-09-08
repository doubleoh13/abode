<?php

namespace App\Models\Financial;

use App\Casts\BigDecimalCast;
use Brick\Math\BigDecimal;
use Database\Factories\Financial\LotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['financial_commodity_id', 'acquired_at', 'cost', 'metadata'])]
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory;

    protected $table = 'financial_lots';

    /**
     * @return BelongsTo<Commodity, $this>
     */
    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class, 'financial_commodity_id');
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class, 'financial_lot_id');
    }

    public function acquiredQuantity(?int $excludeTransactionId = null): BigDecimal
    {
        return BigDecimal::of(
            $this->postings()
                ->where('amount', '>', 0)
                ->when($excludeTransactionId !== null, fn ($query) => $query->where('financial_transaction_id', '!=', $excludeTransactionId))
                ->sum('amount'),
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
            'acquired_at' => 'date',
            'cost' => BigDecimalCast::class,
            'metadata' => 'array',
        ];
    }
}
