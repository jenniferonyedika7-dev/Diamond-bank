<?php

namespace App\Http\Requests;

use App\Support\LoanRepayment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A loan payment as quoted: the payment type, the instalment (for INSTALMENT)
 * and the amount the customer was shown. sp_repay_loan works the amount out
 * again under its locks and answers 409 if it differs. Staff (cash) use this
 * as is; Customer\LoanPaymentRequest adds the account and password.
 */
class LoanPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payment_type' => ['required', 'in:'.implode(',', LoanRepayment::TYPES)],
            'instalment_number' => ['required_if:payment_type,'.LoanRepayment::INSTALMENT, 'nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_type.in' => 'Choose what to pay: the next instalment or everything now.',
            'instalment_number.required_if' => 'Say which instalment is being paid.',
            'amount.gt' => 'The amount must be greater than zero.',
            'amount.decimal' => 'Amounts can have at most 2 decimal places.',
            'amount.max' => 'The amount can be at most 99,999,999.99.',
        ];
    }

    /** The amount exactly as sent, so the procedure sees the decimal string rather than a float. */
    public function amount(): string
    {
        return (string) $this->input('amount');
    }

    /** The instalment number for an INSTALMENT payment, otherwise null. */
    public function instalmentNumber(): ?int
    {
        return $this->validated('payment_type') === LoanRepayment::INSTALMENT ? (int) $this->validated('instalment_number') : null;
    }
}
