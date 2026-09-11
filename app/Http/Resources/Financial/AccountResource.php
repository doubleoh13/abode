<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
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
            'account_type' => $this->account_type,
            'name' => $this->name,
            'path' => $this->path,
            'parent_id' => $this->parent_id,
            'allow_postings' => $this->allow_postings,
            'institution' => new InstitutionResource($this->whenLoaded('institution')),
            'simplefin_account_id' => $this->simplefin_account_id,
            'opened_at' => $this->opened_at?->toDateString(),
            'closed_at' => $this->closed_at?->toDateString(),
        ];
    }
}
