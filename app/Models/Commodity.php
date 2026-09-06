<?php

namespace App\Models;

use App\Enums\CommodityKind;
use App\Enums\SymbolPlacement;
use Database\Factories\CommodityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'kind', 'precision', 'symbol', 'symbol_placement'])]
class Commodity extends Model
{
    /** @use HasFactory<CommodityFactory> */
    use HasFactory;

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
