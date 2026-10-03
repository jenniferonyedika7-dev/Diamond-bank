<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape checks only. The money rules (KYC verified, minimum balance, decimals,
 * currency) belong to sp_open_account, whose messages are returned as 422.
 */
class OpenAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customer,customer_id'],
            'account_type_id' => ['required', 'integer', 'exists:account_type,account_type_id'],
            'initial_deposit' => ['required', 'numeric'],
            'currency_code' => ['nullable', 'string', 'size:3'],
        ];
    }
}
