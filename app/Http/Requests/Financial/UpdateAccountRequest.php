<?php

namespace App\Http\Requests\Financial;

use App\Models\Financial\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class UpdateAccountRequest extends StoreAccountRequest
{
    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $account = $this->account();

                if ($this->createsCycle($account)) {
                    $validator->errors()->add(
                        'parent_id',
                        'An account cannot be moved under itself or one of its descendants.',
                    );
                }

                if (
                    $this->input('account_type') !== $account->account_type->value
                    && $account->children()->exists()
                ) {
                    $validator->errors()->add(
                        'account_type',
                        'The type of an account with children cannot change.',
                    );
                }
            },
        ];
    }

    protected function accountKeepsChildren(): bool
    {
        return $this->account()->children()->exists();
    }

    protected function siblingUniqueNameRule(): Unique
    {
        return parent::siblingUniqueNameRule()->ignore($this->account()->id);
    }

    protected function simpleFinUniqueRule(): Unique
    {
        return parent::simpleFinUniqueRule()->ignore($this->account()->id);
    }

    /**
     * @return Builder<Account>
     */
    protected function siblingAccounts(): Builder
    {
        return parent::siblingAccounts()->whereKeyNot($this->account()->id);
    }

    private function createsCycle(Account $account): bool
    {
        $ancestor = $this->parentAccount();

        while ($ancestor !== null) {
            if ($ancestor->id === $account->id) {
                return true;
            }

            $ancestor = $ancestor->parent;
        }

        return false;
    }

    private function account(): Account
    {
        $account = $this->route('account');

        assert($account instanceof Account);

        return $account;
    }
}
