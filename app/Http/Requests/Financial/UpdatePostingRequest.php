<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Posting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePostingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->postingCarriesStatus()) {
            $this->merge(['status' => null]);
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
            /**
             * Required for asset and liability postings. Ignored and stored as null for all other account types.
             */
            'status' => ['nullable', Rule::enum(PostingStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.enum' => 'Choose a valid status.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->postingCarriesStatus() && $this->input('status') === null) {
                    $validator->errors()->add('status', 'A status is required for asset and liability postings.');
                }
            },
        ];
    }

    protected function postingCarriesStatus(): bool
    {
        $posting = $this->route('posting');

        return $posting instanceof Posting
            && in_array($posting->account->account_type, [AccountType::Asset, AccountType::Liability], true);
    }
}
