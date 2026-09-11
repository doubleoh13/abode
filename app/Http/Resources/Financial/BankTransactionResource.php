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
            'account' => new AccountResource($this->whenLoaded('account')),
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
            'ignored_at' => $this->ignored_at?->toIso8601String(),
            /**
             * Postings on the same account with the same amount within the
             * match window that do not yet settle a bank transaction. Only
             * present on the inbox listing.
             */
            'candidates' => $this->when(isset($this->candidates), fn () => PostingResource::collection($this->candidates)),
        ];
    }
}
