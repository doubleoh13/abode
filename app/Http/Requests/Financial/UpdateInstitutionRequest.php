<?php

namespace App\Http\Requests\Financial;

use Illuminate\Validation\Rules\Unique;

class UpdateInstitutionRequest extends StoreInstitutionRequest
{
    protected function uniqueNameRule(): Unique
    {
        return parent::uniqueNameRule()->ignore($this->route('institution'));
    }
}
