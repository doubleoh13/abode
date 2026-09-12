<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MergeAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * The account that absorbs this one. Must share its type and must
             * not sit beneath it.
             */
            'target_account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_account_id.required' => 'Choose the account to merge into.',
            'target_account_id.integer' => 'Choose a valid account to merge into.',
            'target_account_id.exists' => 'Choose a valid account to merge into.',
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
                if ($validator->errors()->has('target_account_id')) {
                    return;
                }

                $source = $this->sourceAccount();
                $target = $this->targetAccount();

                if ($target->id === $source->id) {
                    $validator->errors()->add('target_account_id', 'An account cannot be merged into itself.');

                    return;
                }

                if ($target->account_type !== $source->account_type) {
                    $validator->errors()->add('target_account_id', 'Accounts can only merge into an account of the same type.');

                    return;
                }

                if (in_array($target->id, $source->subtreeIds(), true)) {
                    $validator->errors()->add('target_account_id', 'An account cannot be merged into one of its own descendants.');

                    return;
                }

                $targetChildSlugs = $target->children()->pluck('name')->map(fn (string $name): string => Str::slug($name));

                foreach ($source->children()->pluck('name') as $childName) {
                    if ($targetChildSlugs->contains(Str::slug($childName))) {
                        $validator->errors()->add('target_account_id', "The target already has a child account named {$childName}.");

                        return;
                    }
                }
            },
        ];
    }

    public function sourceAccount(): Account
    {
        $account = $this->route('account');

        assert($account instanceof Account);

        return $account;
    }

    public function targetAccount(): Account
    {
        return Account::query()->findOrFail($this->integer('target_account_id'));
    }
}
