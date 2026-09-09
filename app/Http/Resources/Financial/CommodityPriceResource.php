<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\CommodityPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommodityPrice
 */
class CommodityPriceResource extends JsonResource
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
            'priced_at' => $this->priced_at->toIso8601String(),
            'price' => (string) $this->price,
        ];
    }
}
