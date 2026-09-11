<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Transaction;
use Brick\Math\BigDecimal;
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
            fn (Validator $validator) => $this->validateReconciledPostings($validator),
            ...parent::after(),
        ];
    }

    /**
     * A reconciled posting is read-only: it can be neither changed nor
     * removed while its status stays reconciled. Sending it back with any
     * other status is the explicit unreconcile that unlocks it.
     */
    protected function validateReconciledPostings(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $reconciled = $this->transaction()->postings()
            ->where('status', PostingStatus::Reconciled)
            ->get()
            ->keyBy('id');

        if ($reconciled->isEmpty()) {
            return;
        }

        $keptIds = [];

        foreach ($this->postingInputs() as $index => $posting) {
            $persisted = isset($posting['id']) ? $reconciled->get((int) $posting['id']) : null;

            if ($persisted === null) {
                continue;
            }

            $keptIds[] = $persisted->id;

            if (($posting['status'] ?? null) !== PostingStatus::Reconciled->value) {
                continue;
            }

            $unchanged = (int) $posting['financial_account_id'] === $persisted->financial_account_id
                && (int) $posting['financial_commodity_id'] === $persisted->financial_commodity_id
                && ($posting['financial_lot_id'] ?? null) === $persisted->financial_lot_id
                && ! isset($posting['lot'])
                && BigDecimal::of($posting['amount'])->isEqualTo($persisted->amount);

            if (! $unchanged) {
                $validator->errors()->add("postings.{$index}.status", 'Unreconcile this posting before changing it.');
            }
        }

        foreach ($reconciled as $posting) {
            if (! in_array($posting->id, $keptIds, true)) {
                $validator->errors()->add('postings', 'Unreconcile a posting before removing it.');

                break;
            }
        }
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

    protected function excludedTransactionIds(): array
    {
        return [$this->transaction()->id];
    }

    private function transaction(): Transaction
    {
        $transaction = $this->route('transaction');

        assert($transaction instanceof Transaction);

        return $transaction;
    }
}
