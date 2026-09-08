<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\BalanceAssertion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BalanceAssertion
 */
class BalanceAssertionResource extends JsonResource
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
            'financial_account_id' => $this->financial_account_id,
            'financial_commodity_id' => $this->financial_commodity_id,
            'commodity' => new CommodityResource($this->whenLoaded('commodity')),
            'asserted_at' => $this->asserted_at->toDateString(),
            'balance' => (string) $this->balance,
            'memo' => $this->memo,
        ];
    }
}
