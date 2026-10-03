<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type_name' => [
                'required', 'string', 'max:50',
                Rule::unique('account_type', 'type_name')->ignore($this->route('account_type')?->account_type_id, 'account_type_id'),
            ],
            'interest_rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'minimum_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
