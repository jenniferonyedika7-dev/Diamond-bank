<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Checked against the stored password by AuthController::changePassword, which counts wrong ones (PasswordLockout).
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults(), 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'The new password must be different from your current password.',
        ];
    }
}
