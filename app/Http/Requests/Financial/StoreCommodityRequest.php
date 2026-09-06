<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\CommodityKind;
use App\Enums\Financial\SymbolPlacement;
use App\Models\Financial\Commodity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreCommodityRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', $this->uniqueCodeRule()],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(CommodityKind::class)],
            'precision' => ['required', 'integer', 'min:0', 'max:8'],
            'symbol' => ['nullable', 'string', 'max:8', 'required_with:symbol_placement'],
            'symbol_placement' => [
                'nullable',
                Rule::enum(SymbolPlacement::class),
                'required_with:symbol',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Enter a commodity code.',
            'code.string' => 'Enter a valid commodity code.',
            'code.max' => 'Commodity codes may not exceed 16 characters.',
            'code.unique' => 'This commodity code is already in use.',
            'name.required' => 'Enter a commodity name.',
            'name.string' => 'Enter a valid commodity name.',
            'name.max' => 'Commodity names may not exceed 255 characters.',
            'kind.required' => 'Choose a commodity kind.',
            'kind.enum' => 'Choose a valid commodity kind.',
            'precision.required' => 'Enter the commodity precision.',
            'precision.integer' => 'Precision must be a whole number.',
            'precision.min' => 'Precision cannot be negative.',
            'precision.max' => 'Precision cannot exceed 8 decimal places.',
            'symbol.string' => 'Enter a valid symbol.',
            'symbol.max' => 'Symbols may not exceed 8 characters.',
            'symbol.required_with' => 'Enter a symbol when choosing its placement.',
            'symbol_placement.enum' => 'Choose a valid symbol placement.',
            'symbol_placement.required_with' => 'Choose where the symbol appears.',
        ];
    }

    protected function uniqueCodeRule(): Unique
    {
        return Rule::unique(Commodity::class, 'code');
    }
}
