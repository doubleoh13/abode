<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\BankTransaction;
use App\Models\Financial\Posting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MatchBankTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'financial_posting_id' => ['required', 'integer', Rule::exists(Posting::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'financial_posting_id.required' => 'Choose a posting to match.',
            'financial_posting_id.integer' => 'Choose a valid posting.',
            'financial_posting_id.exists' => 'Choose a valid posting.',
        ];
    }

    /**
     * The posting must sit on the bank row's account, carry its amount, and
     * not already settle another bank row.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $bankTransaction = $this->route('bank_transaction');
                assert($bankTransaction instanceof BankTransaction);

                $posting = Posting::query()->with('bankTransaction')->findOrFail((int) $this->input('financial_posting_id'));

                if ($posting->financial_account_id !== $bankTransaction->financial_account_id) {
                    $validator->errors()->add('financial_posting_id', 'The posting is on a different account than the bank transaction.');
                } elseif (! $posting->amount->isEqualTo($bankTransaction->amount)) {
                    $validator->errors()->add('financial_posting_id', 'The posting amount does not equal the bank transaction amount.');
                } elseif ($posting->bankTransaction !== null && $posting->bankTransaction->id !== $bankTransaction->id) {
                    $validator->errors()->add('financial_posting_id', 'That posting already settles another bank transaction.');
                }
            },
        ];
    }
}
