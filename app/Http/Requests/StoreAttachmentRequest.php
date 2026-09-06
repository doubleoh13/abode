<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', Rule::in(array_keys(Relation::morphMap()))],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:51200'],
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
                if ($validator->errors()->hasAny(['attachable_type', 'attachable_id'])) {
                    return;
                }

                $modelClass = Relation::getMorphedModel($this->string('attachable_type')->value());

                if ($modelClass === null || ! $modelClass::query()->whereKey($this->integer('attachable_id'))->exists()) {
                    $validator->errors()->add('attachable_id', 'The selected record does not exist.');
                }
            },
        ];
    }
}
