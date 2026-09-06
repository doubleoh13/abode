<?php

namespace App\Http\Requests;

use App\Enums\CommodityKind;
use App\Enums\SymbolPlacement;
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

    protected function uniqueCodeRule(): Unique
    {
        return Rule::unique('commodities', 'code');
    }
}
