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
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $posting = $this->posting();
                $row = $this->route('bank_transaction');

                if (! $row instanceof BankTransaction) {
                    return;
                }

                if ($posting->financial_account_id !== $row->financial_account_id) {
                    $validator->errors()->add('financial_posting_id', 'The posting must be in the same account as the bank transaction.');
                }

                if ($posting->bankTransaction()->exists()) {
                    $validator->errors()->add('financial_posting_id', 'That posting already settles another bank transaction.');
                }
            },
        ];
    }

    public function posting(): Posting
    {
        return Posting::query()->findOrFail((int) $this->input('financial_posting_id'));
    }
}
