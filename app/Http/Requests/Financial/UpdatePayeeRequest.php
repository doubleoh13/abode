<?php

namespace App\Http\Requests\Financial;

use Illuminate\Validation\Rules\Unique;

class UpdatePayeeRequest extends StorePayeeRequest
{
    protected function uniqueNameRule(): Unique
    {
        return parent::uniqueNameRule()->ignore($this->route('payee'));
    }
}
