<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape checks before anything touches money. sp_transfer_funds still enforces
 * ownership, ACTIVE accounts, currency and the minimum balance under row locks.
 */
class TransferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from_account_number' => ['required', 'string', 'max:20'],
            'to_account_number' => ['required', 'string', 'max:20', 'different:from_account_number'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_account_number.different' => "You can't transfer to the same account.",
            'amount.gt' => 'The amount must be greater than zero.',
            'amount.decimal' => 'Amounts can have at most 2 decimal places.',
            'amount.max' => 'The amount can be at most 99,999,999.99.',
        ];
    }
}
