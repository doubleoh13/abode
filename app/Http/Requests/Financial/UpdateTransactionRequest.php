<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class UpdateTransactionRequest extends StoreTransactionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'postings.*.id' => ['nullable', 'integer', 'distinct:strict'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'postings.*.id.integer' => 'The posting is invalid.',
            'postings.*.id.distinct' => 'Each posting may appear only once.',
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validatePostingOwnership($validator),
            ...parent::after(),
        ];
    }

    protected function validatePostingOwnership(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $ownedIds = $this->transaction()->postings()->pluck('id');

        foreach ($this->postingInputs() as $index => $posting) {
            if (isset($posting['id']) && ! $ownedIds->contains((int) $posting['id'])) {
                $validator->errors()->add(
                    "postings.{$index}.id",
                    'The posting does not belong to this transaction.',
                );
            }
        }
    }

    protected function excludedTransactionId(): ?int
    {
        return $this->transaction()->id;
    }

    private function transaction(): Transaction
    {
        $transaction = $this->route('transaction');

        assert($transaction instanceof Transaction);

        return $transaction;
    }
}
