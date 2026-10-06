<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoanTypeRequest extends FormRequest
{
    /** Names are stored in Title Case, like the other reference data. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type_name'))) {
            $this->merge(['type_name' => Str::title(trim($this->input('type_name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'type_name' => [
                'required', 'string', 'max:50',
                Rule::unique('loan_type', 'type_name')->ignore($this->route('loan_type')?->loan_type_id, 'loan_type_id'),
            ],
            // Annual percentage. A loan type without interest isn't allowed (sp_disburse_loan refuses zero interest).
            'interest_rate' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'lt:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'interest_rate.gt' => 'The interest rate must be greater than zero.',
            'interest_rate.lt' => 'The interest rate must be below 100%.',
            'interest_rate.decimal' => 'The interest rate can have at most 2 decimal places.',
        ];
    }
}
