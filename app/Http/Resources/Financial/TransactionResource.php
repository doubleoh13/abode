<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
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
            'date' => $this->date->toDateString(),
            'financial_payee_id' => $this->financial_payee_id,
            'payee' => new PayeeResource($this->whenLoaded('payee')),
            'memo' => $this->memo,
            'metadata' => $this->metadata,
            'status' => $this->status,
            'postings' => PostingResource::collection($this->whenLoaded('postings')),
        ];
    }
}
