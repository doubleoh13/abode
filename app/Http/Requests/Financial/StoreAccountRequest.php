<?php

namespace App\Http\Requests\Financial;

use App\Enums\Financial\AccountType;
use App\Models\Financial\Account;
use App\Models\Financial\Institution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class StoreAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'institution_id' => ['nullable', 'integer', Rule::exists(Institution::class, 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists(Account::class, 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                'not_regex:/:/',
                $this->siblingUniqueNameRule(),
            ],
            'opened_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date', 'after_or_equal:opened_at'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['parent_id', 'account_type'])) {
                    return;
                }

                $parent = $this->parentAccount();

                if ($parent !== null && $parent->account_type->value !== $this->input('account_type')) {
                    $validator->errors()->add(
                        'account_type',
                        'A child account must have the same type as its parent.',
                    );
                }
            },
        ];
    }

    protected function siblingUniqueNameRule(): Unique
    {
        return Rule::unique(Account::class, 'name')
            ->where('parent_id', $this->input('parent_id'))
            ->where('account_type', $this->input('account_type'));
    }

    protected function parentAccount(): ?Account
    {
        if ($this->input('parent_id') === null) {
            return null;
        }

        return Account::query()->find($this->integer('parent_id'));
    }
}
