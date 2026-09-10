<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\RecurringTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringTransaction
 */
class RecurringTransactionResource extends JsonResource
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
            'financial_payee_id' => $this->financial_payee_id,
            'payee' => new PayeeResource($this->whenLoaded('payee')),
            'memo' => $this->memo,
            'metadata' => $this->metadata,
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'starts_on' => $this->starts_on->toDateString(),
            'next_due_on' => $this->next_due_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'lead_days' => $this->lead_days,
            'postings' => RecurringPostingResource::collection($this->whenLoaded('postings')),
        ];
    }
}
