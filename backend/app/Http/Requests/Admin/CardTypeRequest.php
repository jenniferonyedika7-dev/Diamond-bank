<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CardTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type_name' => [
                'required', 'string', 'max:50',
                Rule::unique('card_type', 'type_name')->ignore($this->route('card_type')?->card_type_id, 'card_type_id'),
            ],
            'daily_limit' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
        ];
    }
}
