<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\RecurringPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringPosting
 */
class RecurringPostingResource extends JsonResource
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
            'position' => $this->position,
            'status' => $this->status,
            'financial_account_id' => $this->financial_account_id,
            'account' => new AccountResource($this->whenLoaded('account')),
            'financial_commodity_id' => $this->financial_commodity_id,
            'commodity' => new CommodityResource($this->whenLoaded('commodity')),
            'amount' => (string) $this->amount,
            'memo' => $this->memo,
            'metadata' => $this->metadata,
        ];
    }
}
