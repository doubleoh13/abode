<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Payee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StorePayeeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a payee name.',
            'name.string' => 'Enter a valid payee name.',
            'name.max' => 'Payee names may not exceed 255 characters.',
            'name.unique' => 'This payee already exists.',
        ];
    }

    protected function uniqueNameRule(): Unique
    {
        return Rule::unique(Payee::class, 'name');
    }
}
