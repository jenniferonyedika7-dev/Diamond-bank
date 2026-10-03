<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterStaffRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'national_id' => ['required', 'string', 'max:30', 'unique:employee,national_id'],
            'position' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:150', 'unique:employee,email'],
            'user_name' => ['required', 'string', 'max:50', 'unique:users,user_name'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
