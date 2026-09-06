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

    protected $table = 'financial_commodities';

    /**
     * @return HasMany<CommodityPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(CommodityPrice::class);
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
