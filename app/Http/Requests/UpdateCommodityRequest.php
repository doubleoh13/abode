<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateCommodityRequest extends StoreCommodityRequest
{
    protected function uniqueCodeRule(): Unique
    {
        return parent::uniqueCodeRule()->ignore($this->route('commodity'));
    }
}
