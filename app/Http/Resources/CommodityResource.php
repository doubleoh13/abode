<?php

namespace App\Http\Resources;

use App\Models\Commodity;
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
            'precision' => $this->precision,
            'symbol' => $this->symbol,
            'symbol_placement' => $this->symbol_placement,
        ];
    }
}
