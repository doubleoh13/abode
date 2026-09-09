<?php

namespace App\Models\Financial;

use App\Enums\Financial\CommodityKind;
use App\Enums\Financial\PriceSource;
use App\Enums\Financial\SymbolPlacement;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNotes;
use Database\Factories\Financial\CommodityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'kind', 'display_precision', 'symbol', 'symbol_placement', 'price_source', 'price_symbol'])]
class Commodity extends Model
{
    /** @use HasFactory<CommodityFactory> */
    use HasAttachments, HasFactory, HasNotes;

    public const string BASE_CURRENCY_CODE = 'USD';

    protected $table = 'financial_commodities';

    private static ?self $baseCurrency = null;

    /**
     * Memoized for the process lifetime, so its attributes can outlive
     * updates — read only the immutable id and code from it.
     */
    public static function baseCurrency(): self
    {
        return self::$baseCurrency ??= self::query()->where('code', self::BASE_CURRENCY_CODE)->firstOrFail();
    }

    /**
     * @return HasMany<CommodityPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(CommodityPrice::class, 'financial_commodity_id');
    }

    /**
     * Select the most recent price point alongside each commodity.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withLatestPrice(Builder $query): void
    {
        $query->addSelect([
            'latest_price' => CommodityPrice::query()
                ->select('price')
                ->whereColumn('financial_commodity_id', 'financial_commodities.id')
                ->latest('priced_at')
                ->limit(1),
            'latest_priced_at' => CommodityPrice::query()
                ->select('priced_at')
                ->whereColumn('financial_commodity_id', 'financial_commodities.id')
                ->latest('priced_at')
                ->limit(1),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CommodityKind::class,
            'display_precision' => 'integer',
            'symbol_placement' => SymbolPlacement::class,
            'price_source' => PriceSource::class,
        ];
    }
}
