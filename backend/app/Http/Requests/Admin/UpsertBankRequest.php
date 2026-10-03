<?php

namespace App\Http\Requests\Admin;

use App\Models\Bank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertBankRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'swift_code' => [
                'required', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/',
                Rule::unique('bank', 'swift_code')->ignore(Bank::query()->value('bank_id'), 'bank_id'),
            ],
            'established_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'swift_code.regex' => 'The SWIFT code must be 8 or 11 letters or digits.',
            'established_date.before_or_equal' => 'The established date cannot be in the future.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('swift_code'))) {
            $this->merge(['swift_code' => strtoupper(trim($this->input('swift_code')))]);
        }
    }
}
