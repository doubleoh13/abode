<?php

namespace App\Models\Financial;

use App\Enums\Financial\CommodityKind;
use App\Enums\Financial\SymbolPlacement;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasNotes;
use Database\Factories\Financial\CommodityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'kind', 'precision', 'symbol', 'symbol_placement'])]
class Commodity extends Model
{
    /** @use HasFactory<CommodityFactory> */
    use HasAttachments, HasFactory, HasNotes;

    public const string BASE_CURRENCY_CODE = 'USD';

    protected $table = 'financial_commodities';

    private static ?self $baseCurrency = null;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CommodityKind::class,
            'precision' => 'integer',
            'symbol_placement' => SymbolPlacement::class,
        ];
    }
}
