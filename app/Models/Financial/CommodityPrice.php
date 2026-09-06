<?php

namespace App\Models\Financial;

use Database\Factories\Financial\CommodityPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commodity_id', 'price', 'priced_at'])]
class CommodityPrice extends Model
{
    /** @use HasFactory<CommodityPriceFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'financial_commodity_prices';

    /**
     * @return BelongsTo<Commodity, $this>
     */
    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:12',
            'priced_at' => 'immutable_datetime',
        ];
    }
}
