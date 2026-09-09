<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\CommodityKind;
use App\Enums\Financial\PriceSource;
use App\Enums\Financial\SymbolPlacement;
use App\Models\Financial\Commodity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreCommodityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper($this->input('code'))]);
        }
    }

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
            /**
             * Decimal places used for display only (0–25). Changing this never changes stored amounts or costs.
             */
            'display_precision' => ['required', 'integer', 'min:0', 'max:25'],
            'symbol' => ['nullable', 'string', 'max:8', 'required_with:symbol_placement'],
            'symbol_placement' => [
                'nullable',
                Rule::enum(SymbolPlacement::class),
                'required_with:symbol',
            ],
            'price_source' => ['nullable', Rule::enum(PriceSource::class)],
            /**
             * The source's quote symbol (e.g. a Yahoo ticker). Required for fetchable sources, absent otherwise.
             */
            'price_symbol' => [
                'nullable',
                'string',
                'max:32',
                Rule::requiredIf(fn (): bool => $this->fetchableSource()),
                Rule::prohibitedIf(fn (): bool => ! $this->fetchableSource()),
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
            'display_precision.required' => 'Enter the display precision.',
            'display_precision.integer' => 'Precision must be a whole number.',
            'display_precision.min' => 'Precision cannot be negative.',
            'display_precision.max' => 'Display precision cannot exceed 25 decimal places.',
            'symbol.string' => 'Enter a valid symbol.',
            'symbol.max' => 'Symbols may not exceed 8 characters.',
            'symbol.required_with' => 'Enter a symbol when choosing its placement.',
            'symbol_placement.enum' => 'Choose a valid symbol placement.',
            'symbol_placement.required_with' => 'Choose where the symbol appears.',
            'price_source.enum' => 'Choose a valid price source.',
            'price_symbol.required' => 'Enter the quote symbol for this price source.',
            'price_symbol.prohibited' => 'A quote symbol only applies to fetchable price sources.',
            'price_symbol.max' => 'Quote symbols may not exceed 32 characters.',
        ];
    }

    protected function fetchableSource(): bool
    {
        $source = PriceSource::tryFrom((string) $this->input('price_source'));

        return $source !== null && $source->fetchable();
    }

    protected function uniqueCodeRule(): Unique
    {
        return Rule::unique(Commodity::class, 'code');
    }
}
