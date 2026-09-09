<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\Commodity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Commodity
 */
class CommodityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'display_precision' => $this->display_precision,
            'symbol' => $this->symbol,
            'symbol_placement' => $this->symbol_placement,
            'price_source' => $this->price_source,
            'price_symbol' => $this->price_symbol,
            'latest_price' => $this->when(
                isset($this->latest_price),
                fn (): string => (string) BigDecimal::of($this->latest_price)->strippedOfTrailingZeros(),
            ),
            'latest_priced_at' => $this->when(
                isset($this->latest_priced_at),
                fn (): string => CarbonImmutable::parse($this->latest_priced_at)->toDateString(),
            ),
        ];
    }
}
