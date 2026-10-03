<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'gender' => ['required', 'in:M,F'],
            'national_id' => ['required', 'string', 'max:30', 'unique:customer,national_id'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:150', 'unique:customer,email'],
            'branch_id' => ['required', 'integer', 'exists:branch,branch_id'],
            'user_name' => ['required', 'string', 'max:50', 'unique:users,user_name'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before_or_equal' => 'You must be at least 18 years old to register.',
        ];
    }
}
