<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNoteRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'noteable_type' => ['required', Rule::in(array_keys(Relation::morphMap()))],
            'noteable_id' => ['required', 'integer'],
            'body' => ['required', 'string'],
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
                if ($validator->errors()->hasAny(['noteable_type', 'noteable_id'])) {
                    return;
                }

                $modelClass = Relation::getMorphedModel($this->string('noteable_type')->value());

                if ($modelClass === null || ! $modelClass::query()->whereKey($this->integer('noteable_id'))->exists()) {
                    $validator->errors()->add('noteable_id', 'The selected record does not exist.');
                }
            },
        ];
    }
}
