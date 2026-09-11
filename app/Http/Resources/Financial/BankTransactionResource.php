<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankTransaction
 */
class BankTransactionResource extends JsonResource
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
            'source' => $this->source,
            'external_id' => $this->external_id,
            'posted_on' => $this->posted_on->toDateString(),
            'transacted_on' => $this->transacted_on?->toDateString(),
            'pending' => $this->pending,
            'amount' => (string) $this->amount->strippedOfTrailingZeros(),
            'currency' => $this->currency,
            'description' => $this->description,
            'payee' => $this->payee,
            'memo' => $this->memo,
            'financial_posting_id' => $this->financial_posting_id,
            'rejected_posting_ids' => $this->rejected_posting_ids ?? [],
            'candidate_posting_id' => $this->resource->getAttribute('candidate_posting_id'),
        ];
    }
}
