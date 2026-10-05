<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'account_number' => ['required', 'string', 'max:20'],
            'card_type_id' => ['required', 'integer', 'exists:card_type,card_type_id'],
        ];
    }
}
