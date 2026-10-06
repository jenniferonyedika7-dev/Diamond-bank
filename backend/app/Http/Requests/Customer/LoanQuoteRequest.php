<?php

namespace App\Http\Requests\Customer;

use App\Support\Amortisation;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/** The loan terms: type, amount, term and repayment plan. Limits come from config('bank.loans'). */
class LoanQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        $limits = config('bank.loans');

        return [
            'loan_type_id' => ['required', 'integer', 'exists:loan_type,loan_type_id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', "min:{$limits['min_amount']}", "max:{$limits['max_amount']}"],
            'term_months' => ['required', 'integer', "min:{$limits['min_term_months']}", "max:{$limits['max_term_months']}"],
            'repayment_plan' => ['required', 'in:'.implode(',', Amortisation::PLANS)],
        ];
    }

    public function messages(): array
    {
        $limits = config('bank.loans');
        $term = "Loans can run for {$limits['min_term_months']} to {$limits['max_term_months']} months.";

        return [
            'loan_type_id.exists' => 'Choose one of the loan types.',
            'amount.decimal' => 'Amounts can have at most 2 decimal places.',
            'amount.min' => 'The minimum loan is '.Money::dalasi($limits['min_amount']).'.',
            'amount.max' => 'The maximum loan is '.Money::dalasi($limits['max_amount']).'.',
            'term_months.min' => $term,
            'term_months.max' => $term,
            'repayment_plan.in' => 'Choose monthly instalments or a single payment at the end of the term.',
        ];
    }

    /** The amount exactly as sent, so BCMath sees the decimal string rather than a float. */
    public function amount(): string
    {
        return (string) $this->input('amount');
    }
}
