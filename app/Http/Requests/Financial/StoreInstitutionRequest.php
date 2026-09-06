<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Institution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreInstitutionRequest extends FormRequest
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
            'name.required' => 'Enter an institution name.',
            'name.string' => 'Enter a valid institution name.',
            'name.max' => 'Institution names may not exceed 255 characters.',
            'name.unique' => 'This institution already exists.',
        ];
    }

    protected function uniqueNameRule(): Unique
    {
        return Rule::unique(Institution::class, 'name');
    }
}
