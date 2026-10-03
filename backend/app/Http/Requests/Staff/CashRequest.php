<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/** Deposit and withdraw. Amount rules beyond "a number" are enforced by sp_deposit / sp_withdraw. */
class CashRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
