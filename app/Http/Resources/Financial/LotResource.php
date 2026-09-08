<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\Lot;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lot
 */
class LotResource extends JsonResource
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
            'financial_commodity_id' => $this->financial_commodity_id,
            'commodity' => new CommodityResource($this->whenLoaded('commodity')),
            'acquired_at' => $this->acquired_at->toDateString(),
            'cost' => (string) $this->cost,
            'metadata' => $this->metadata,
            'open_quantity' => $this->when(isset($this->open_quantity), fn (): string => (string) BigDecimal::of($this->open_quantity)->strippedOfTrailingZeros()),
            'acquired_quantity' => $this->when(isset($this->acquired_quantity), fn (): string => (string) BigDecimal::of($this->acquired_quantity)->strippedOfTrailingZeros()),
        ];
    }
}
