<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * A full replacement transaction plus the two transactions it absorbs.
 * The absorbed postings are excluded from lot quantities so a merge can
 * re-reference the lots those postings opened.
 */
class MergeTransactionsRequest extends StoreTransactionRequest
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
            'financial_transaction_ids' => ['required', 'array', 'size:2'],
            'financial_transaction_ids.*' => ['required', 'integer', 'distinct:strict', Rule::exists(Transaction::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'financial_transaction_ids.required' => 'Choose two transactions to merge.',
            'financial_transaction_ids.array' => 'Choose two transactions to merge.',
            'financial_transaction_ids.size' => 'Choose exactly two transactions to merge.',
            'financial_transaction_ids.*.required' => 'Choose a valid transaction.',
            'financial_transaction_ids.*.integer' => 'Choose a valid transaction.',
            'financial_transaction_ids.*.distinct' => 'Choose two different transactions.',
            'financial_transaction_ids.*.exists' => 'Choose a valid transaction.',
        ];
    }

    /**
     * @return list<int>
     */
    public function absorbedTransactionIds(): array
    {
        return array_map(intval(...), array_values($this->validated('financial_transaction_ids')));
    }

    protected function excludedTransactionIds(): array
    {
        return collect($this->input('financial_transaction_ids', []))
            ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
