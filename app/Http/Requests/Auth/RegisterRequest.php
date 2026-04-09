<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'    => 'required|string|min:2|max:50|regex:/^[a-zA-Z\s\-]+$/',
            'last_name'     => 'required|string|min:2|max:50|regex:/^[a-zA-Z\s\-]+$/',
            'email'         => 'required|email:rfc,dns|unique:users,email|max:255',
            'phone' => [
                'required', 
                'string', 
                'regex:/^(\+234|0)[789][01]\d{8}$/',
                'unique:users,phone'
            ],
            'password'      => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'referral_code' => 'nullable|string|exists:users,referral_code',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid Nigerian phone number (e.g. 08012345678 or +2348012345678).',
            'email.unique'=> 'An account with this email already exists.',
            'phone.unique'=> 'An account with this phone number already exists.',
        ];
    }
}
