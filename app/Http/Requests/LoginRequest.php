<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            /**
             * Names the token so it can be recognized and revoked later,
             * typically the device it lives on.
             */
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Enter your password.',
            'device_name.required' => 'Name the device so the token can be recognized later.',
            'device_name.max' => 'Use a device name under 255 characters.',
        ];
    }

    /**
     * The user the submitted credentials belong to.
     *
     * @throws ValidationException when the credentials do not match
     */
    public function authenticatedUser(): User
    {
        $user = User::query()->where('email', Str::lower($this->string('email')))->first();

        if ($user === null || ! Hash::check($this->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        return $user;
    }
}
