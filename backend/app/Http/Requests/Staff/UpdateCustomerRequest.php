<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The registration rules (RegisterCustomerRequest), with unique checks ignoring this customer. */
class UpdateCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        $customerId = $this->route('customer')->customer_id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'gender' => ['required', 'in:M,F'],
            'national_id' => ['required', 'string', 'max:30', Rule::unique('customer', 'national_id')->ignore($customerId, 'customer_id')],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:150', Rule::unique('customer', 'email')->ignore($customerId, 'customer_id')],
            'branch_id' => ['required', 'integer', 'exists:branch,branch_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before_or_equal' => 'The customer must be at least 18 years old.',
        ];
    }

    /** The national ID is part of KYC: once verified or rejected it is fixed. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $customer = $this->route('customer');
                if ($customer->kyc_status !== 'PENDING' && $this->input('national_id') !== $customer->national_id) {
                    $validator->errors()->add('national_id', 'The national ID can only be changed while KYC is pending.');
                }
            },
        ];
    }
}
