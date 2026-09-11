<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Account;
use App\Models\Financial\BalanceAssertion;
use App\Models\Financial\Commodity;
use App\Rules\ExactDecimal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBalanceAssertionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'financial_account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
            'financial_commodity_id' => ['required', 'integer', Rule::exists(Commodity::class, 'id')],
            'asserted_at' => [
                'required',
                'date',
                Rule::unique(BalanceAssertion::class, 'asserted_at')
                    ->where('financial_account_id', $this->integer('financial_account_id'))
                    ->where('financial_commodity_id', $this->integer('financial_commodity_id')),
            ],
            /**
             * The expected balance at the end of the asserted date as a decimal string,
             * e.g. "1523.47". Maximum 53 integer and 25 fractional digits.
             *
             * @var string
             *
             * @example 1523.47
             */
            'balance' => [
                'required',
                new ExactDecimal(allowNegative: true, allowZero: true, message: 'Enter a valid balance.'),
            ],
            'memo' => ['nullable', 'string', 'max:255'],
            /**
             * When true and the journal agrees with the balance, every posting in this
             * account and commodity dated on or before the assertion is marked reconciled.
             * Counter legs in other accounts are never touched.
             */
            'reconcile_postings' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'financial_account_id.required' => 'Choose an account.',
            'financial_account_id.exists' => 'Choose a valid account.',
            'financial_commodity_id.required' => 'Choose a commodity.',
            'financial_commodity_id.exists' => 'Choose a valid commodity.',
            'asserted_at.required' => 'Enter an assertion date.',
            'asserted_at.date' => 'Enter a valid assertion date.',
            'asserted_at.unique' => 'An assertion already exists for this account, commodity, and date.',
            'memo.max' => 'The memo may not exceed 255 characters.',
        ];
    }
}
