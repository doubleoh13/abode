<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\RecurrenceFrequency;
use App\Models\Financial\Commodity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The transaction payload plus a repeat rule. Serves store, update, and
 * preview alike: postings are replaced wholesale on update, so no posting
 * ids are needed.
 */
class StoreRecurringTransactionRequest extends StoreTransactionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset(
            $rules['postings.*.financial_lot_id'],
            $rules['postings.*.lot'],
            $rules['postings.*.lot.acquired_at'],
            $rules['postings.*.lot.cost'],
        );

        return [
            ...$rules,
            /**
             * When the next occurrence is due. Anything due within the lead
             * window posts immediately on save.
             */
            'date' => ['required', 'date'],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            /**
             * Repeat every N periods of the frequency: 2 with weekly is every other week.
             */
            'interval' => ['required', 'integer', 'min:1', 'max:365'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:date'],
            /**
             * Days ahead of the due date to post. Null uses the application default.
             */
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'date.required' => 'Enter the next due date.',
            'date.date' => 'Enter a valid due date.',
            'frequency.required' => 'Choose how often this repeats.',
            'frequency.enum' => 'Choose a valid frequency.',
            'interval.required' => 'Enter how many periods to wait between occurrences.',
            'interval.integer' => 'Enter a whole number of periods.',
            'interval.min' => 'The interval must be at least 1.',
            'interval.max' => 'The interval may not exceed 365.',
            'ends_on.date' => 'Enter a valid end date.',
            'ends_on.after_or_equal' => 'The end date must not precede the next due date.',
            'lead_days.integer' => 'Enter a whole number of days.',
            'lead_days.min' => 'Lead days may not be negative.',
            'lead_days.max' => 'Lead days may not exceed 365.',
        ];
    }

    /**
     * Lot checks are replaced by a base-currency requirement: a lot needs a
     * price on the day it is acquired, which a template cannot carry.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validatePostableAccounts($validator),
            fn (Validator $validator) => $this->validatePostingStatuses($validator),
            fn (Validator $validator) => $this->validateBaseCurrencyOnly($validator),
            fn (Validator $validator) => $this->validateBalance($validator),
        ];
    }

    protected function validateBaseCurrencyOnly(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $baseCurrencyId = Commodity::baseCurrency()->id;

        foreach ($this->postingInputs() as $index => $posting) {
            if ((int) $posting['financial_commodity_id'] !== $baseCurrencyId) {
                $validator->errors()->add(
                    "postings.{$index}.financial_commodity_id",
                    'Scheduled postings must use the base currency.',
                );
            }
        }
    }
}
