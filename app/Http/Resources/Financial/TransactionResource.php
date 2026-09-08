<?php

namespace App\Http\Resources\Financial;

use App\Enums\Financial\PostingStatus;
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
            /**
             * Derived from the loaded postings; omitted when they are not
             * part of the response, since deriving it would lazy-load every
             * sibling posting and its account.
             */
            'status' => $this->when($this->relationLoaded('postings'), fn (): ?PostingStatus => $this->status),
            'postings' => PostingResource::collection($this->whenLoaded('postings')),
        ];
    }
}
