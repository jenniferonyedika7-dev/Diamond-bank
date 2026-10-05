<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Only user_name is read; anything else in the body (a password, say) is ignored. */
class UsernameRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('user_name'))) {
            $this->merge(['user_name' => trim($this->input('user_name'))]);
        }
    }

    public function rules(): array
    {
        $userId = $this->route('staffUser')?->user_id ?? $this->route('customer')?->user?->user_id;

        return [
            'user_name' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'user_name')->ignore($userId, 'user_id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_name.regex' => 'The username may only contain letters, numbers, dots, dashes and underscores.',
        ];
    }
}
