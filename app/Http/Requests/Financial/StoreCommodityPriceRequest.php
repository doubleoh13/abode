<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use App\Rules\ExactDecimal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommodityPriceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'financial_commodity_id' => ['required', 'integer', Rule::exists(Commodity::class, 'id')],
            'priced_at' => [
                'required',
                'date',
                Rule::unique(CommodityPrice::class, 'priced_at')
                    ->where('financial_commodity_id', $this->integer('financial_commodity_id')),
            ],
            /**
             * The base-currency price as a nonnegative decimal string, e.g. "312.4799".
             * Maximum 53 integer and 25 fractional digits.
             *
             * @var string
             *
             * @example 312.4799
             */
            'price' => [
                'required',
                new ExactDecimal(allowNegative: false, allowZero: true, message: 'Enter a valid price.'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'financial_commodity_id.required' => 'Choose a commodity.',
            'financial_commodity_id.exists' => 'Choose a valid commodity.',
            'priced_at.required' => 'Enter a price time.',
            'priced_at.date' => 'Enter a valid price time.',
            'priced_at.unique' => 'A price already exists for this commodity and time.',
        ];
    }
}
