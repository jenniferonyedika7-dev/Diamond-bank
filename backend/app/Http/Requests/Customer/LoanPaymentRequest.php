<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\LoanPaymentRequest as BaseLoanPaymentRequest;

/** An online loan payment: from one of the customer's own accounts, confirmed with their password. */
class LoanPaymentRequest extends BaseLoanPaymentRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'account_number' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ];
    }
}
